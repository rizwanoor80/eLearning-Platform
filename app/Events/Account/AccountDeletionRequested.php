<?php

namespace App\Events\Account;

use App\Models\AccountDeletionRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountDeletionRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public AccountDeletionRequest $request) {}
}
