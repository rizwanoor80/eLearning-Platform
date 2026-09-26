<?php

return [

    /*
    | How far a webhook's signed timestamp may be from now before it is refused. The event id
    | table already makes a replay a no-op; this bounds how old a captured request stays valid.
    */
    'webhook_tolerance_seconds' => 900,

    /*
    | The lesson-room clock (PRD §9, CP6). The room is created this long before the lesson starts,
    | either party may join this long before it starts, and the room closes this long after the
    | scheduled end. Fixed by the PRD, not admin settings.
    */
    'room_lead_minutes' => 15,
    'join_lead_minutes' => 10,
    'close_grace_minutes' => 10,

    'daily' => [
        'base_url' => 'https://api.daily.co/v1',
    ],

];
