<?php

namespace Tests\Support\Payments;

use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * 23b (R247): the HTTP double every Stripe test uses — nothing in CI or the cloud sandbox calls
 * Stripe. `stripe-php` has one static HTTP client, so `install()` swaps it and `uninstall()` puts the
 * default back. A request with no matching reply throws, so an unplanned call is a failing test and never
 * a network call. Replies are matched on `METHOD /v1/path` (a prefix), first unused match wins.
 */
final class FakeStripeHttp implements ClientInterface
{
    /** @var list<array{key: string, status: int, body: array<string, mixed>, used: bool}> */
    private array $replies = [];

    /** @var list<array{method: string, path: string, params: array<string, mixed>, headers: list<string>}> */
    public array $requests = [];

    public static function install(): self
    {
        $http = new self;
        ApiRequestor::setHttpClient($http);

        return $http;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function reply(string $methodAndPath, array $body, int $status = 200): self
    {
        $this->replies[] = ['key' => $methodAndPath, 'status' => $status, 'body' => $body, 'used' => false];

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $key = strtoupper($method).' '.$path;

        $this->requests[] = ['method' => strtoupper($method), 'path' => $path, 'params' => $params, 'headers' => $headers];

        foreach ($this->replies as $i => $reply) {
            if (! $reply['used'] && str_starts_with($key, $reply['key'])) {
                $this->replies[$i]['used'] = true;

                return [json_encode($reply['body']), $reply['status'], []];
            }
        }

        throw new ApiConnectionException("Unplanned Stripe request in a test: {$key}");
    }

    /**
     * @return list<array{method: string, path: string, params: array<string, mixed>, headers: list<string>}>
     */
    public function requestsTo(string $methodAndPath): array
    {
        return array_values(array_filter($this->requests, fn (array $r): bool => str_starts_with($r['method'].' '.$r['path'], $methodAndPath)));
    }

    public function idempotencyKeyOf(array $request): ?string
    {
        foreach ($request['headers'] as $header) {
            if (stripos($header, 'Idempotency-Key:') === 0) {
                return trim(substr($header, strlen('Idempotency-Key:')));
            }
        }

        return null;
    }
}
