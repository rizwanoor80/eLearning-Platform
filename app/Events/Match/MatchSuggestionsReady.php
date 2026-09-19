<?php

namespace App\Events\Match;

use App\Models\MatchRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MatchSuggestionsReady
{
    use Dispatchable, SerializesModels;

    public function __construct(public MatchRequest $matchRequest) {}
}
