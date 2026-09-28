<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * R149(a): `MessageMasker::orFail()`/`hasCandidate()` throw this, never a bare `RuntimeException`, when
 * masking itself cannot complete (a PCRE engine failure — backtrack limit, bad UTF-8 — or `Normalizer`
 * failing). A dedicated subclass exists so a caller can catch exactly "the message could not be masked"
 * without also swallowing `QueryException`/`PDOException` or Symfony's `HttpException`, both of which
 * extend `RuntimeException` too and would otherwise be miscategorised as a masking failure — e.g. a
 * database error inside `SendMessage`'s transaction reported back to the sender as "could not be
 * masked" instead of surfacing as the real error it is.
 */
class MessageMaskingFailedException extends RuntimeException
{
    // Mirrors `ConversationClosedException::NOTICE`: the flashed/thrown text is the same generic
    // sentence a sender sees, decoupled from whatever `MessageMasker`'s throw sites pass to the
    // constructor, so a future edit to those call sites can't accidentally leak PCRE engine detail
    // (backtrack-limit text, offsets) into a toast.
    public const NOTICE = 'The message could not be masked, so it was not stored.';
}
