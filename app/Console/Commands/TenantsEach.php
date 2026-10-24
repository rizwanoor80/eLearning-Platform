<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Tenancy\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class TenantsEach extends Command
{
    protected $signature = 'tenants:each {artisan : The artisan command line to run, quoted} {--tenant= : Only this slug}';

    protected $description = 'Run an artisan command once per active tenant; a failure in one tenant does not stop the others.';

    public function handle(TenantManager $manager): int
    {
        $line = (string) $this->argument('artisan');
        $failed = [];

        $tenants = Tenant::query()
            ->where('status', TenantStatus::Active)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get();

        if ($tenants->isEmpty() && $this->option('tenant')) {
            $this->error('No active tenant ['.$this->option('tenant').'].');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            try {
                $code = $manager->run($tenant, function () use ($line): int {
                    $code = Artisan::call($line);
                    $this->output->write(Artisan::output());

                    return $code;
                });
            } catch (Throwable $e) {
                report($e);
                $code = self::FAILURE;
                $this->error("[{$tenant->slug}] ".$e->getMessage());
            }

            if ($code !== self::SUCCESS) {
                $failed[] = $tenant->slug;
            }
        }

        if ($failed !== []) {
            $this->error('Failed on: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
