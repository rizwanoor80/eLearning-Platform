<?php

namespace App\Events\Tutor;

use App\Models\TutorProfile;
use Illuminate\Foundation\Events\Dispatchable;

class TutorRejected
{
    use Dispatchable;

    public function __construct(public TutorProfile $profile) {}
}
