<?php

return [

    /*
    | The owner-only switch for live money (R246, ADR-030, HOW-WE-WORK §8). While false, no
    | `payment_gateways` row can be saved with `mode = live` and a live row never resolves to a
    | driver. It is an environment flag, not a `settings` row: the app has one admin role, so any
    | admin, seeder or migration could flip a database setting, whereas this needs the server's
    | environment — a change that is itself a named authorisation. There is no UI or command for it.
    */
    'live_mode_allowed' => (bool) env('PAYMENT_LIVE_MODE_ALLOWED', false),

];
