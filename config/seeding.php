<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seeded admin credentials (R10)
    |--------------------------------------------------------------------------
    |
    | Read from env() only here, never directly in a seeder, so config:cache
    | keeps working (env() reads nothing once the config cache is built).
    |
    */

    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
