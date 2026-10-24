<?php

namespace Tests\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class LeaksTransactionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Opens a transaction on the tenant connection and never ends it. */
    public function handle(): void
    {
        DB::beginTransaction();
        DB::table('tenancy_probe')->insert(['note' => 'leaked']);
    }
}
