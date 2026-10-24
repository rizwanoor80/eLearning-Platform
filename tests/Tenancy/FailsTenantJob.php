<?php

namespace Tests\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FailsTenantJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $mark) {}

    public function handle(): void
    {
        throw new RuntimeException('boom '.$this->mark);
    }

    /** Runs before the JobFailed event: it has to see the tenant the job was dispatched under. */
    public function failed(Throwable $e): void
    {
        DB::table('tenancy_probe')->insert(['note' => 'failed:'.$this->mark]);
    }
}
