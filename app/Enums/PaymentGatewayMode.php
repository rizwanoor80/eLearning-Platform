<?php

namespace App\Enums;

/**
 * `test` talks to a gateway's sandbox; `live` moves real money. Switching any row to `live` is a
 * named authorisation (HOW-WE-WORK §8) and is refused in code unless the owner-only setting
 * allows it.
 */
enum PaymentGatewayMode: string
{
    case Test = 'test';
    case Live = 'live';
}
