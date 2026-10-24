<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenancyException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;

/**
 * Every queued payload — jobs, queued listeners, mailables, notifications — carries the tenant id it was dispatched
 * under, and the worker runs it under that tenant. A payload without one is refused: one queue serves every tenant
 * (R286), so "no tenant" can only mean a bug. So is one for a tenant that is not Active: the job fails into the central
 * failed_jobs and can be retried once the tenant is back.
 *
 * The previous tenant is restored when the job finishes — which is what lets the sync driver run a job inside a
 * request without ending the request's tenant — keyed per job so nested and out-of-order events cannot pop the wrong
 * entry. There is deliberately no listener on JobExceptionOccurred: a final failure runs the job's own failed() hook
 * before JobFailed is raised, and that hook must still see its tenant. Whatever a worker leaves behind (a job that
 * died without either event) is restored when the worker loops.
 */
final class TenantQueue
{
    /** @var array<int, ?Tenant> job object id => the tenant that was current before the job started */
    private array $previous = [];

    public function __construct(private readonly TenantManager $tenants) {}

    public function register(): void
    {
        $key = config('tenancy.payload_key');

        Queue::createPayloadUsing(function () use ($key): array {
            return $this->tenants->current() !== null
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

            $tenant = Tenant::query()->findOrFail((int) $id);

            if (! $tenant->isActive()) {
                throw TenancyException::tenantNotRunnable($tenant->slug, $tenant->status->value);
            }

            $this->previous[spl_object_id($event->job)] = $this->tenants->current();
            $this->tenants->initialize($tenant);
        });

        foreach ([JobProcessed::class, JobFailed::class] as $event) {
            Event::listen($event, function ($event): void {
                $this->restore(spl_object_id($event->job));
            });
        }

        // A listener must return null: a false return would stop the worker.
        Event::listen(Looping::class, function (): void {
            $this->restoreAll();
        });
    }

    private function restore(int $job): void
    {
        if (! $this->tenants->enabled() || ! array_key_exists($job, $this->previous)) {
            return;
        }

        $previous = $this->previous[$job];
        unset($this->previous[$job]);

        $previous === null ? $this->tenants->end() : $this->tenants->initialize($previous);
    }

    private function restoreAll(): void
    {
        if ($this->previous === []) {
            return;
        }

        $oldest = array_key_first($this->previous);
        $previous = $this->previous[$oldest];
        $this->previous = [];

        if ($this->tenants->enabled()) {
            $previous === null ? $this->tenants->end() : $this->tenants->initialize($previous);
        }
    }
}
