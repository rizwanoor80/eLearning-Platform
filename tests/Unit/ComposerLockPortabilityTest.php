<?php

use Symfony\Component\Process\Process;

/*
 * R200 / ADR-028. The Claude Code cloud's GitHub proxy serves only the session's own repository, so Composer cannot
 * download zipballs there and has to clone every package over git. A package locked without a git source makes
 * `composer install` impossible in the cloud. PHPStan, the one package that never had one, is a pinned phar instead.
 */

const FETCH_SCRIPT = __DIR__.'/../../scripts/fetch-phpstan.php';
const PIN_FILE = __DIR__.'/../../scripts/phpstan.sha256';

it('locks every package with a git source, a URL and a commit', function () {
    $lock = json_decode(file_get_contents(__DIR__.'/../../composer.lock'), true, flags: JSON_THROW_ON_ERROR);

    $withoutGitSource = [];

    foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
        $source = $package['source'] ?? [];

        if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($source['reference'])) {
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

it('has fetched the phar the pin names', function () {
    $pin = parse_ini_file(PIN_FILE);
    $phar = __DIR__.'/../../tools/phpstan.phar';

    expect(is_file($phar))->toBeTrue()
        ->and(hash_file('sha256', $phar))->toBe($pin['sha256']);
});

/**
 * Runs a copy of the fetch script inside a throw-away project root (the script locates `tools/` and its pin file
 * relative to itself), so no test touches the real tools/ directory or the network.
 *
 * @param  array<string, string>  $files  path relative to the sandbox root => contents
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string, 2: string, 3: string} exit code, stdout, stderr, sandbox root
 */
function runFetchScript(array $files, array $env = []): array
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

    $process = new Process([PHP_BINARY, $root.'/scripts/fetch-phpstan.php'], $root, $env);
    $process->run();

    return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), $root];
}

it('leaves a phar whose checksum already matches the pin untouched and does not need the network', function () {
    $phar = 'not really a phar';
    $pin = "version=2.2.14\nsha256=".hash('sha256', $phar)."\n";

    [$exit, $out, , $root] = runFetchScript(['scripts/phpstan.sha256' => $pin, 'tools/phpstan.phar' => $phar]);

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
]);
