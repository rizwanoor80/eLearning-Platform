<?php

use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Tests\TestCase;

uses(TestCase::class);

it('provisions at least one active supervisor for every environment Horizon runs in', function () {
    // local, rehearsal and production are the only environments that ever boot
    // Horizon (CI runs the suite on a sync queue, so `testing` never does) --
    // see docs/PROJECT_BRIEF.md's "Local / Rehearsal / Production" environments.
    // A name missing from config/horizon.php's `environments` silently
    // provisions zero supervisors: ProvisioningPlan::deploy() matches names
    // with Str::is() and returns early when nothing matches, while the
    // Horizon master process still reports "running" (ADR-023).
    $environments = ['local', 'rehearsal', 'production'];

    $plan = new ProvisioningPlan('horizon', config('horizon.environments'), config('horizon.defaults', []));

    foreach ($environments as $environment) {
        $supervisors = collect($plan->parsed)->first(
            fn ($_, $name) => Str::is($name, $environment)
        );

        expect($supervisors)->not->toBeEmpty("no Horizon supervisor matches environment [{$environment}]");

        $active = collect($supervisors)->filter(fn ($options) => $options->maxProcesses > 0);

        expect($active)->not->toBeEmpty("every supervisor for [{$environment}] has maxProcesses 0 -- Horizon would run with zero workers");
    }
});
