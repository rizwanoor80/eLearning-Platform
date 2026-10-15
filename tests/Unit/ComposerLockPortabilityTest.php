<?php

use Symfony\Component\Process\Process;

/*
 * R200 / ADR-028. The Claude Code cloud's GitHub proxy serves only the session's own repository, so Composer cannot
 * download zipballs there and has to clone every package over git. A package locked without a git source makes
 * `composer install` impossible in the cloud. PHPStan, the one package that never had one, is a pinned phar instead.
 */

const FETCH_SCRIPT = __DIR__.'/../../scripts/fetch-phpstan.php';
const PIN_FILE = __DIR__.'/../../scripts/phpstan.sha256';

it('locks every package with a github.com git source and a commit hash', function () {
    $lock = json_decode(file_get_contents(__DIR__.'/../../composer.lock'), true, flags: JSON_THROW_ON_ERROR);

    $withoutGitSource = [];

    foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
        $source = $package['source'] ?? [];

        // The cloud's proxy serves GitHub over https only, and a branch name is not a commit.
        $cloneable = ($source['type'] ?? null) === 'git'
            && preg_match('#^https://github\.com/[\w.-]+/[\w.-]+$#', (string) ($source['url'] ?? '')) === 1
            && preg_match('/^[0-9a-f]{40}$/D', (string) ($source['reference'] ?? '')) === 1;

        if (! $cloneable) {
            $withoutGitSource[] = $package['name'].' '.$package['version'];
        }
    }

    expect($lock['packages'])->not->toBeEmpty()
        ->and($withoutGitSource)->toBe([]);
});

it('keeps PHPStan out of Composer and pinned to one version in three places', function () {
    $lock = json_decode(file_get_contents(__DIR__.'/../../composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $pin = parse_ini_file(PIN_FILE);

    $locked = array_column(array_merge($lock['packages'], $lock['packages-dev']), 'name');

    // Larastan requires phpstan/phpstan, so the root package replaces it; the version it replaces is the phar's.
    expect($locked)->not->toContain('phpstan/phpstan')
        ->and($locked)->toContain('larastan/larastan')
        ->and($composer['replace']['phpstan/phpstan'])->toBe($pin['version'])
        ->and($pin['sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($composer['scripts']['types:check'][0])->toBe('@php tools/phpstan.phar analyse --memory-limit=512M')
        ->and($composer['scripts']['post-install-cmd'])->toContain('@php scripts/fetch-phpstan.php')
        ->and($composer['scripts']['post-update-cmd'])->toContain('@php scripts/fetch-phpstan.php')
        ->and($composer['scripts']['post-autoload-dump'])->toContain('@php artisan wayfinder:generate --with-form');
});

it('leaves the install type to the environment, so the cloud can choose git clones', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    // A project-level "preferred-install" beats `composer config -g preferred-install source` (the cloud's setting).
    expect($composer['config'])->not->toHaveKey('preferred-install');
});

it('git-ignores the fetched phar and every generated Wayfinder directory', function () {
    $ignored = file(__DIR__.'/../../.gitignore', FILE_IGNORE_NEW_LINES);

    expect($ignored)->toContain('/tools/')
        ->and($ignored)->toContain('/resources/js/actions')
        ->and($ignored)->toContain('/resources/js/routes')
        ->and($ignored)->toContain('/resources/js/wayfinder');
});

it('has fetched the phar the pin names', function () {
    $pin = parse_ini_file(PIN_FILE);
    $phar = __DIR__.'/../../tools/phpstan.phar';

    if (! is_file($phar)) {
        $this->fail('tools/phpstan.phar is missing: run `composer install` or `php scripts/fetch-phpstan.php`.');
    }

    expect(hash_file('sha256', $phar))->toBe($pin['sha256']);
});

/**
 * Runs a copy of the fetch script inside a throw-away project root (the script locates `tools/` and its pin file
 * relative to itself), so no test touches the real tools/ directory or the network. A download is a file in the
 * sandbox: PHPSTAN_PHAR_URL in $env names it relative to the sandbox root and the script reads it as file://.
 *
 * @param  array<string, string>  $files  path relative to the sandbox root => contents
 * @param  array<string, string>  $env
 * @param  array<string, int>  $mtimes  path relative to the sandbox root => modification time to give it
 * @return array{0: int, 1: string, 2: string, 3: string} exit code, stdout, stderr, sandbox root
 */
function runFetchScript(array $files, array $env = [], array $mtimes = []): array
{
    $root = sys_get_temp_dir().'/phpstan-fetch-'.bin2hex(random_bytes(6));
    mkdir($root.'/scripts', 0777, true);
    copy(FETCH_SCRIPT, $root.'/scripts/fetch-phpstan.php');

    register_shutdown_function(function () use ($root) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($root);
    });

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0777, true);
        }

        file_put_contents($root.'/'.$path, $contents);
    }

    foreach ($mtimes as $path => $time) {
        touch($root.'/'.$path, $time);
    }

    if (isset($env['PHPSTAN_PHAR_URL'])) {
        $path = str_replace('\\', '/', $root.'/'.$env['PHPSTAN_PHAR_URL']);
        $env['PHPSTAN_PHAR_URL'] = 'file://'.($path[0] === '/' ? '' : '/').$path;
    }

    // `false` unsets a variable the test process itself inherited (composer test sets COMPOSER_DEV_MODE=1).
    $process = new Process([PHP_BINARY, $root.'/scripts/fetch-phpstan.php'], $root, $env + ['COMPOSER_DEV_MODE' => false, 'PHPSTAN_PHAR_URL' => false]);
    $process->run();

    return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), $root];
}

/** A pin whose checksum is that of $bytes, so a download of $bytes is "the phar". */
function pinFor(string $bytes): string
{
    return "version=2.2.14\nsha256=".hash('sha256', $bytes)."\n";
}

it('leaves a phar whose checksum already matches the pin untouched and does not need the network', function () {
    $phar = 'not really a phar';

    [$exit, $out, , $root] = runFetchScript(['scripts/phpstan.sha256' => pinFor($phar), 'tools/phpstan.phar' => $phar]);

    expect($exit)->toBe(0)
        ->and($out)->toContain('checksum verified')
        ->and(file_get_contents($root.'/tools/phpstan.phar'))->toBe($phar)
        ->and(glob($root.'/tools/*'))->toBe([$root.'/tools/phpstan.phar']);
});

it('does nothing on a --no-dev install', function () {
    [$exit, $out, , $root] = runFetchScript(
        ['scripts/phpstan.sha256' => "version=2.2.14\nsha256=".str_repeat('a', 64)."\n"],
        ['COMPOSER_DEV_MODE' => '0'],
    );

    expect($exit)->toBe(0)
        ->and($out)->toContain('skipped')
        ->and(is_dir($root.'/tools'))->toBeFalse();
});

it('refuses a malformed pin before it touches the network', function (string $pin) {
    [$exit, , $err, $root] = runFetchScript(['scripts/phpstan.sha256' => $pin]);

    expect($exit)->toBe(1)
        ->and($err)->toContain('needs a version')
        ->and(is_dir($root.'/tools'))->toBeFalse();
})->with([
    'version with a path in it' => ["version=../../evil\nsha256=".'a7d45c01d3bd5aceb2cb9e596a67e50ff9f12b8757a373b93c0761deb8cd77e1'."\n"],
    'checksum too short' => ["version=2.2.14\nsha256=abc\n"],
    'no checksum' => ["version=2.2.14\n"],
    'checksum one character too long' => ["version=2.2.14\nsha256=".'a7d45c01d3bd5aceb2cb9e596a67e50ff9f12b8757a373b93c0761deb8cd77e1'."0\n"],
    'checksum with a prefix' => ["version=2.2.14\nsha256=x".'a7d45c01d3bd5aceb2cb9e596a67e50ff9f12b8757a373b93c0761deb8cd77e1'."\n"],
    'version with a suffix' => ["version=1.2.3/../x\nsha256=".'a7d45c01d3bd5aceb2cb9e596a67e50ff9f12b8757a373b93c0761deb8cd77e1'."\n"],
    'version with a prefix' => ["version=x1.2.3\nsha256=".'a7d45c01d3bd5aceb2cb9e596a67e50ff9f12b8757a373b93c0761deb8cd77e1'."\n"],
    'four-part version' => ["version=1.2.3.4\nsha256=".'a7d45c01d3bd5aceb2cb9e596a67e50ff9f12b8757a373b93c0761deb8cd77e1'."\n"],
    'checksum with a newline inside quotes (the INI parser rejects the file)' => ["version=2.2.14\nsha256=\"".'a7d45c01d3bd5aceb2cb9e596a67e50ff9f12b8757a373b93c0761deb8cd77e1'."\n\"\n"],
]);

it('installs a download whose checksum matches the pin', function () {
    $phar = 'the pinned phar';

    [$exit, $out, , $root] = runFetchScript(
        ['scripts/phpstan.sha256' => pinFor($phar), 'source/phpstan.phar' => $phar],
        ['PHPSTAN_PHAR_URL' => 'source/phpstan.phar'],
    );

    expect($exit)->toBe(0)
        ->and($out)->toContain('checksum verified')
        ->and(file_get_contents($root.'/tools/phpstan.phar'))->toBe($phar)
        ->and(glob($root.'/tools/*'))->toBe([$root.'/tools/phpstan.phar']);
});

it('refuses a download whose checksum does not match the pin and keeps no file', function (string $download) {
    [$exit, , $err, $root] = runFetchScript(
        ['scripts/phpstan.sha256' => pinFor('the pinned phar'), 'source/phpstan.phar' => $download],
        ['PHPSTAN_PHAR_URL' => 'source/phpstan.phar'],
    );

    expect($exit)->toBe(1)
        ->and($err)->toContain('checksum mismatch')
        ->and(glob($root.'/tools/*'))->toBe([]);
})->with([
    'other bytes' => ['a different file'],
    'truncated' => ['the pinned'],
    'empty' => [''],
]);

it('replaces a stale phar with a download that matches the pin', function () {
    $phar = 'the pinned phar';

    [$exit, , , $root] = runFetchScript(
        ['scripts/phpstan.sha256' => pinFor($phar), 'tools/phpstan.phar' => 'an old release', 'source/phpstan.phar' => $phar],
        ['PHPSTAN_PHAR_URL' => 'source/phpstan.phar'],
    );

    expect($exit)->toBe(0)
        ->and(file_get_contents($root.'/tools/phpstan.phar'))->toBe($phar);
});

it('removes a stale phar when the refresh fails or does not match, so types:check cannot run it', function (array $files, string $message) {
    [$exit, , $err, $root] = runFetchScript(
        ['scripts/phpstan.sha256' => pinFor('the pinned phar'), 'tools/phpstan.phar' => 'an old release'] + $files,
        ['PHPSTAN_PHAR_URL' => 'source/phpstan.phar'],
    );

    expect($exit)->toBe(1)
        ->and($err)->toContain($message)
        ->and(glob($root.'/tools/*'))->toBe([]);
})->with([
    'download missing' => [[], 'download failed'],
    'download of other bytes' => [['source/phpstan.phar' => 'a different file'], 'checksum mismatch'],
]);

it('sweeps temporary files an earlier killed run left behind, and only old ones', function () {
    $phar = 'the pinned phar';
    $old = time() - 7200;

    [$exit, , , $root] = runFetchScript(
        [
            'scripts/phpstan.sha256' => pinFor($phar),
            'source/phpstan.phar' => $phar,
            'tools/phpstan.phar.111.tmp' => 'killed download',
            'tools/phpstan.phar.222.tmp' => 'another install still running',
            'tools/notes.txt' => 'not ours',
            'tools/phpstan.phar.333.keep' => 'not a temporary file',
        ],
        ['PHPSTAN_PHAR_URL' => 'source/phpstan.phar'],
        ['tools/phpstan.phar.111.tmp' => $old, 'tools/notes.txt' => $old, 'tools/phpstan.phar.333.keep' => $old],
    );

    expect($exit)->toBe(0)
        ->and(file_exists($root.'/tools/phpstan.phar.111.tmp'))->toBeFalse()
        ->and(file_exists($root.'/tools/phpstan.phar.222.tmp'))->toBeTrue()
        ->and(file_exists($root.'/tools/notes.txt'))->toBeTrue()
        ->and(file_exists($root.'/tools/phpstan.phar.333.keep'))->toBeTrue();
});
