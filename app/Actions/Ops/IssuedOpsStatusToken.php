<?php

namespace App\Actions\Ops;

use App\Models\OpsStatusToken;
use SensitiveParameter;

/** The one moment the plaintext token exists: handed to the admin page, shown once, never stored. */
final readonly class IssuedOpsStatusToken
{
    public function __construct(
        public OpsStatusToken $token,
        #[SensitiveParameter] public string $plainToken,
    ) {}
}
