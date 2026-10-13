<?php

use App\Http\Controllers\Ops\OpsStatusController;
use App\Http\Middleware\AuthenticateOpsStatus;
use Illuminate\Support\Facades\Route;

/*
| Operator routes (R193). Loaded from bootstrap/app.php outside the `web` group: no session, no
| cookies, no CSRF, no Inertia — a bearer-token JSON endpoint and nothing else. The throttle runs
| before the token check so a guessing client is slowed, keyed by address, whether or not it is right.
*/
Route::get('/ops/status', OpsStatusController::class)
    ->middleware(['throttle:ops-status', AuthenticateOpsStatus::class])
    ->name('ops.status');
