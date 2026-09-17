<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * R24: closes cycle 01's review note #6 — scripts/rtl-check.sh had no
 * regression test of its own logic. Runs the real script (via the real
 * Process facade, not faked) against throwaway fixture files so a future
 * silent weakening of the pattern is caught here, not in CI months later.
 */
function rtlFixtureDir(): string
{
    return storage_path('framework/testing/rtl-check-fixtures-'.Str::random(8));
}

it('fails on a physical Tailwind utility', function () {
    $dir = rtlFixtureDir();
    mkdir($dir, recursive: true);
    file_put_contents($dir.'/Physical.vue', '<template><div class="ml-4">x</div></template>');

    $result = Process::path(base_path())->run(['bash', 'scripts/rtl-check.sh', $dir.'/']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('ml-4');

    File::deleteDirectory($dir);
});

it('passes on the logical equivalent', function () {
    $dir = rtlFixtureDir();
    mkdir($dir, recursive: true);
    file_put_contents($dir.'/Logical.vue', '<template><div class="ms-4">x</div></template>');

    $result = Process::path(base_path())->run(['bash', 'scripts/rtl-check.sh', $dir.'/']);

    expect($result->exitCode())->toBe(0);

    File::deleteDirectory($dir);
});

it('still fails a genuine violation sharing a line with an exempt slide- keyframe class', function () {
    $dir = rtlFixtureDir();
    mkdir($dir, recursive: true);
    file_put_contents(
        $dir.'/Mixed.vue',
        '<template><div class="slide-in-from-left-2 pr-4">x</div></template>',
    );

    $result = Process::path(base_path())->run(['bash', 'scripts/rtl-check.sh', $dir.'/']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('pr-4');

    File::deleteDirectory($dir);
});
