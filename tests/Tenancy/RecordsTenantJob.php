<?php

namespace Tests\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RecordsTenantJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $mark) {}

    public function handle(): void
    {
        DB::table('tenancy_probe')->insert(['note' => 'job:'.$this->mark]);
    }
}
