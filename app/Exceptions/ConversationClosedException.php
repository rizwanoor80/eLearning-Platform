<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A message to a conversation with a suspended party (R133). The text is the one both sides see, and
 * says nothing of who is suspended or why.
 */
class ConversationClosedException extends RuntimeException
{
    public const NOTICE = 'This conversation is closed.';

    public function __construct()
    {
        parent::__construct(self::NOTICE);
    }
}
