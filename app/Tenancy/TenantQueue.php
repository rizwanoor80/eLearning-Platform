<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenancyException;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;

/**
 * Every queued payload — jobs, queued listeners, mailables, notifications — carries the tenant id it was dispatched
 * under, and the worker runs it under that tenant. A payload without one is refused: one queue serves every tenant
 * (R286), so "no tenant" can only mean a bug. The previous tenant is restored afterwards, which is what lets the sync
 * driver run a job inside a request without ending the request's tenant.
 */
final class TenantQueue
{
    /** @var list<?Tenant> */
    private array $previous = [];

    public function __construct(private readonly TenantManager $tenants) {}

    public function register(): void
    {
        $key = config('tenancy.payload_key');

        Queue::createPayloadUsing(function () use ($key): array {
            return $this->tenants->enabled() && $this->tenants->current() !== null
                ? [$key => $this->tenants->current()->getKey()]
                : [];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($key): void {
            if (! $this->tenants->enabled()) {
                return;
            }

            $id = $event->job->payload()[$key] ?? null;

            if ($id === null) {
                throw TenancyException::jobWithoutTenant($event->job->resolveName());
            }

            $this->previous[] = $this->tenants->current();
            $this->tenants->initialize(Tenant::query()->findOrFail((int) $id));
        });

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
            Event::listen($event, fn () => $this->restore());
        }
    }

    private function restore(): void
    {
        if (! $this->tenants->enabled() || $this->previous === []) {
            return;
        }

        $previous = array_pop($this->previous);
        $previous === null ? $this->tenants->end() : $this->tenants->initialize($previous);
    }
}
