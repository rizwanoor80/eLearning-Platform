<?php

namespace App\Events\Tutor;

use App\Models\TutorProfile;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TutorPermitExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public TutorProfile $profile) {}
}
