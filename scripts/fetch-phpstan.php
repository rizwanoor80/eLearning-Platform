<?php

/*
 * Puts the pinned PHPStan phar at tools/phpstan.phar (ADR-028, R200).
 *
 * PHPStan's Composer package is a zipball with no git source, and the Claude Code cloud's GitHub proxy serves only
 * this repository, so Composer cannot install it there. composer.json therefore "replace"s phpstan/phpstan and this
 * script fetches the same release from raw.githubusercontent.com instead.
 *
 * The pin lives in scripts/phpstan.sha256 (version and SHA-256). The only things this script does are: read the
 * pin, hash an existing phar, download one file from the one fixed URL into a temporary file, hash that file and
 * rename it into place. It never includes, executes or shells out to anything it downloaded, and a file whose hash
 * differs from the pin is deleted, never kept.
 *
 * It is PHP rather than shell because PowerShell's `bash` is the WSL stub on the owner's machine (CLAUDE.md: no WSL),
 * and PHP is present wherever Composer runs.
 */

$root = dirname(__DIR__);
$pinFile = __DIR__.'/phpstan.sha256';
$directory = $root.'/tools';
$target = $directory.'/phpstan.phar';

// `composer install --no-dev` (the Forge deploy) runs this hook too; production never runs PHPStan.
if (getenv('COMPOSER_DEV_MODE') === '0') {
    echo "PHPStan phar: skipped (--no-dev install)\n";

    exit(0);
}

$pin = is_file($pinFile) ? parse_ini_file($pinFile) : false;
$version = is_array($pin) ? (string) ($pin['version'] ?? '') : '';
$expected = is_array($pin) ? strtolower((string) ($pin['sha256'] ?? '')) : '';

if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1 || preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
    fwrite(STDERR, "PHPStan phar: scripts/phpstan.sha256 needs a version (1.2.3) and a 64-character sha256.\n");

    exit(1);
}

if (is_file($target) && hash_equals($expected, (string) hash_file('sha256', $target))) {
    echo "PHPStan phar: tools/phpstan.phar is $version (checksum verified)\n";

    exit(0);
}

if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    fwrite(STDERR, "PHPStan phar: cannot create $directory\n");

    exit(1);
}

$url = "https://raw.githubusercontent.com/phpstan/phpstan/$version/phpstan.phar";
$temporary = $target.'.'.getmypid().'.tmp';

echo "PHPStan phar: fetching $version from raw.githubusercontent.com\n";

$context = stream_context_create([
    'http' => ['timeout' => 120, 'follow_location' => 1, 'max_redirects' => 3, 'ignore_errors' => false],
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
]);

$copied = @copy($url, $temporary, $context);
$actual = $copied && is_file($temporary) ? (string) hash_file('sha256', $temporary) : '';

if (! $copied || ! hash_equals($expected, $actual)) {
    @unlink($temporary);

    fwrite(STDERR, $copied
        ? "PHPStan phar: checksum mismatch for $url\n  expected $expected\n  actual   $actual\n"
        : "PHPStan phar: download failed for $url\n");

    exit(1);
}

if (is_file($target)) {
    @unlink($target);
}

if (! rename($temporary, $target)) {
    @unlink($temporary);

    fwrite(STDERR, "PHPStan phar: cannot move the verified file to $target\n");

    exit(1);
}

@chmod($target, 0755);

echo "PHPStan phar: tools/phpstan.phar is $version (checksum verified)\n";
