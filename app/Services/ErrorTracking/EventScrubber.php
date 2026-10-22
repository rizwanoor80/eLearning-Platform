<?php

namespace App\Services\ErrorTracking;

use Illuminate\Database\QueryException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\Mailer\Exception\ExceptionInterface;

/**
 * R276: the last stop before anything is sent to the error tracker. The app's own data (card and bank details, tokens,
 * parents' messages) must never reach a third party, so this removes rather than trusts: request bodies and cookies are
 * dropped whole, the user is dropped, and every other array is walked and any value under a sensitive key is replaced.
 * Secrets that surface inside a message or a URL are masked too.
 *
 * A key is sensitive if, once camelCase and punctuation are turned into underscores, it names a password, secret, token,
 * key, credential, session, signature, cookie or Authorization header, or a card or bank field.
 */
final class EventScrubber
{
    public const REDACTED = '[redacted]';

    private const SENSITIVE_KEY = '/(password|passwd|passphrase|passcode|pwd|secret|token|authorization|cookie|credential|signature|session|csrf|xsrf|card|cvc|cvv|cvn|iban|bank|routing|swift|sort_?code|account_?(number|no)|api_?key|private_?key|recovery|email|phone|body|dsn|last4|(^|_)(key|pw|otp|pin|jwt|bic|mfa|acct)(_|$))/';

    private const BEARER = '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i';

    /** Exceptions whose message carries user data: bound SQL values, DB error DETAIL lines, recipient addresses. */
    private const OPAQUE_EXCEPTIONS = [\PDOException::class, QueryException::class, ExceptionInterface::class];

    private const DEPTH_LIMIT = 8;

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        // Never the body or the cookies, whatever their keys.
        unset($request['data'], $request['cookies']);

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = self::string($request['url']);
        }

        if (isset($request['query_string']) && is_string($request['query_string'])) {
            $request['query_string'] = self::string($request['query_string']);
        }

        $event->setRequest(self::array($request));
        $event->setExtra(self::array($event->getExtra()));
        $event->setTags(self::tags($event->getTags()));
        $event->setUser(null);

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string) $name, self::array($context));
        }

        if ($event->getMessage() !== null) {
            $event->setMessage(self::string($event->getMessage()), self::array($event->getMessageParams()), $event->getMessageFormatted() === null ? null : self::string($event->getMessageFormatted()));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::exceptionValue($exception->getType(), $exception->getValue()));

            // Arguments recorded per call (when `zend.exception_ignore_args` is 0) can hold tokens and passwords.
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars([]);
            }
        }

        $event->setBreadcrumb(array_map(fn (Breadcrumb $crumb): Breadcrumb => self::breadcrumb($crumb), $event->getBreadcrumbs()));

        return $event;
    }

    /** Tracing is off; if it is ever switched on by mistake, no transaction is sent. */
    public static function dropTransaction(Event $event, ?EventHint $hint = null): null
    {
        return null;
    }

    public static function beforeBreadcrumb(Breadcrumb $crumb): Breadcrumb
    {
        return self::breadcrumb($crumb);
    }

    public static function isSensitiveKey(int|string $key): bool
    {
        $normalised = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', (string) $key)));

        return preg_match(self::SENSITIVE_KEY, $normalised) === 1;
    }

    public static function string(string $value): string
    {
        $masked = preg_replace(self::BEARER, '$1 '.self::REDACTED, $value) ?? self::REDACTED;

        // Header lines (`Authorization: …`, `X-Api-Key: …`, `Cookie: …`) lose everything after the name.
        $masked = preg_replace('/\b(proxy-authorization|authorization|set-cookie|cookie|x-[a-z-]*(?:key|token|secret))\s*:[^\r\n]*/i', '$1: '.self::REDACTED, $masked) ?? self::REDACTED;

        // Provider secret keys, and anything shaped like a card number.
        $masked = preg_replace(['/\b[sr]k_(?:live|test)_[A-Za-z0-9]+/', '/(?<![\w.])(?:\d[ -]?){13,19}(?!\d)/'], self::REDACTED, $masked) ?? self::REDACTED;

        // The token in a password-reset link path.
        $masked = preg_replace('#(reset-password/)[^/?\#\s]+#i', '$1'.self::REDACTED, $masked) ?? self::REDACTED;

        // JSON pairs: "password":"…" keeps the key and loses the value.
        $masked = preg_replace_callback('/"([^"\\\\]+)"(\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/', fn (array $m): string => self::isSensitiveKey($m[1]) ? '"'.$m[1].'"'.$m[2].'"'.self::REDACTED.'"' : $m[0], $masked) ?? self::REDACTED;

        // A `key=value` pair anywhere (query string, `--token=abc`, `password=x`) whose key is sensitive keeps the key and loses the value.
        return preg_replace_callback('/(?<![A-Za-z0-9_.%\[\]-])([A-Za-z0-9_.%\[\]-]+)=([^&;#\s"\']*)/', fn (array $m): string => self::isSensitiveKey(urldecode($m[1])) ? $m[1].'='.self::REDACTED : $m[0], $masked) ?? self::REDACTED;
    }

    /** A message that can hold user data is replaced by its class; any other is masked like every other string. */
    private static function exceptionValue(string $type, string $message): string
    {
        foreach (self::OPAQUE_EXCEPTIONS as $opaque) {
            if (is_a($type, $opaque, true)) {
                return self::REDACTED;
            }
        }

        return self::string($message);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function array(array $data, int $depth = 0): array
    {
        if ($depth >= self::DEPTH_LIMIT) {
            return [self::REDACTED];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (self::isSensitiveKey($key)) {
                $clean[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $clean[$key] = self::array($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = self::string($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                // Objects and resources are not inspected; their class name is all that is kept.
                $clean[$key] = is_object($value) ? $value::class : self::REDACTED;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private static function tags(array $tags): array
    {
        $clean = [];

        foreach ($tags as $key => $value) {
            $clean[$key] = self::isSensitiveKey($key) ? self::REDACTED : self::string($value);
        }

        return $clean;
    }

    private static function breadcrumb(Breadcrumb $crumb): Breadcrumb
    {
        return new Breadcrumb(
            $crumb->getLevel(),
            $crumb->getType(),
            $crumb->getCategory(),
            $crumb->getMessage() === null ? null : self::string($crumb->getMessage()),
            self::array($crumb->getMetadata()),
            $crumb->getTimestamp(),
        );
    }
}
