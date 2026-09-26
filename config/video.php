<?php

return [

    /*
    | How far a webhook's signed timestamp may be from now before it is refused. The event id
    | table already makes a replay a no-op; this bounds how old a captured request stays valid.
    */
    'webhook_tolerance_seconds' => 900,

    'daily' => [
        'base_url' => 'https://api.daily.co/v1',
    ],

];
