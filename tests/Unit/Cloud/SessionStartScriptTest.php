<?php

use Symfony\Component\Process\Process;

/*
 * Cycle 19d item 4 (R233): the cloud session-start hook sets the postgres role password from DB_PASSWORD only when it
 * is non-empty. It runs in an empty directory with stub commands first on PATH, so no service, role or file of this
 * checkout is touched; the stub `sudo` records what it was asked to run.
 */

/**
 * @return array{output: string, sudo: string}
 */
function runSessionStart(?string $dbPassword): array
{
    $dir = sys_get_temp_dir().'/session-start-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0777, true);
    mkdir($dir.'/work');

    foreach (['service', 'pg_isready'] as $noop) {
        file_put_contents($dir.'/bin/'.$noop, "#!/bin/sh\nexit 0\n");
    }
    // Logs its arguments and stdin; answers "1" so the `SELECT 1 FROM pg_database` checks find a database.
    file_put_contents($dir.'/bin/sudo', "#!/bin/sh\necho \"ARGS: \$*\" >> \"$dir/sudo.log\"\ncat >> \"$dir/sudo.log\"\necho 1\n");

    foreach (['service', 'pg_isready', 'sudo'] as $stub) {
        chmod($dir.'/bin/'.$stub, 0755);
    }

    $env = ['PATH' => $dir.'/bin:/usr/bin:/bin', 'CLAUDE_CODE_REMOTE' => 'true'];
    // `false` removes a variable the test runner itself inherited (a cloud session exports both).
    $env['DB_PASSWORD'] = $dbPassword ?? false;

    $process = new Process(['bash', dirname(__DIR__, 3).'/scripts/cloud-session-start.sh'], $dir.'/work', $env);
    $process->run();

    $result = [
        'output' => $process->getOutput().$process->getErrorOutput(),
        'sudo' => is_file($dir.'/sudo.log') ? (string) file_get_contents($dir.'/sudo.log') : '',
    ];

    (new Process(['rm', '-rf', $dir]))->run();

    return $result;
}

beforeEach(function () {
    if ((new Process(['bash', '-c', 'true']))->run() !== 0) {
        $this->markTestSkipped('bash is not available');
    }
});

it('sets the postgres role password from a non-empty DB_PASSWORD', function () {
    $run = runSessionStart('secret-for-test');

    expect($run['sudo'])->toContain('ALTER USER postgres PASSWORD')->toContain('pw=secret-for-test')
        ->and($run['output'])->not->toContain('DB_PASSWORD is empty');
});

it('leaves the role password alone, and says so, when DB_PASSWORD is empty or unset', function (?string $value) {
    $run = runSessionStart($value);

    expect($run['sudo'])->not->toContain('ALTER USER')
        ->and($run['output'])->toContain('DB_PASSWORD is empty or unset');
})->with(['exported but empty' => '', 'unset' => null]);

it('does nothing on a machine that is not a cloud session', function () {
    $process = new Process(['bash', dirname(__DIR__, 3).'/scripts/cloud-session-start.sh'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin', 'DB_PASSWORD' => 'x', 'CLAUDE_CODE_REMOTE' => false]);
    $process->run();

    expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toBe('');
});
