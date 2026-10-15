<?php

/*
 * Puts the pinned PHPStan phar at tools/phpstan.phar (ADR-028, R200).
 *
 * PHPStan's Composer package is a zipball with no git source, and the Claude Code cloud's GitHub proxy serves only
 * this repository, so Composer cannot install it there. composer.json therefore "replace"s phpstan/phpstan and this
 * script fetches the same release from raw.githubusercontent.com instead.
 *
 * The pin lives in scripts/phpstan.sha256 (version and SHA-256). The only things this script does are: read the
 * pin, hash an existing phar, download one file into a temporary file, hash that file and rename it into place. It
 * never includes, executes or shells out to anything it downloaded. The SHA-256 is the only integrity gate: after a
 * run, tools/phpstan.phar is either the file the pin names or absent, because a file that does not match the pin is
 * deleted whether it was already there or has just been downloaded.
 *
 * The download goes through ext-curl, which honours the environment's proxy variables (HTTPS_PROXY, NO_PROXY) like
 * Composer does; PHP's own http streams ignore them. Without ext-curl the script falls back to the streams and says
 * so when a proxy is configured. PHPSTAN_PHAR_URL, a file:// URL, replaces the source for the tests; the hash gate
 * applies to it unchanged.
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

$pin = is_file($pinFile) ? parse_ini_file($pinFile, false, INI_SCANNER_RAW) : false;
$version = is_array($pin) ? (string) ($pin['version'] ?? '') : '';
$expected = is_array($pin) ? strtolower((string) ($pin['sha256'] ?? '')) : '';

if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1) {
    fwrite(STDERR, "PHPStan phar: scripts/phpstan.sha256 needs a version (1.2.3) and a 64-character sha256.\n");

    exit(1);
}

if (is_file($target) && hash_equals($expected, (string) hash_file('sha256', $target))) {
    echo "PHPStan phar: tools/phpstan.phar is $version (checksum verified)\n";

    exit(0);
}

// Whatever is at the target now is not the pinned phar (an old pin, a damaged file, a planted one): it goes before
// anything is fetched, so a failed refresh leaves no phar rather than a stale one that `types:check` would run.
if ((is_file($target) || is_link($target)) && ! @unlink($target)) {
    fwrite(STDERR, "PHPStan phar: cannot remove $target, which does not match the pin\n");

    exit(1);
}

if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    fwrite(STDERR, "PHPStan phar: cannot create $directory\n");

    exit(1);
}

// A run killed mid-download (Composer's 300 s timeout) leaves its temporary file behind: sweep the old ones.
foreach (scandir($directory) ?: [] as $name) {
    $path = $directory.'/'.$name;

    if (str_starts_with($name, 'phpstan.phar.') && str_ends_with($name, '.tmp') && is_file($path) && filemtime($path) < time() - 3600) {
        @unlink($path);
    }
}

$override = (string) getenv('PHPSTAN_PHAR_URL');
$url = str_starts_with($override, 'file://') ? $override : "https://raw.githubusercontent.com/phpstan/phpstan/$version/phpstan.phar";
$temporary = $target.'.'.getmypid().'.tmp';

/** Downloads $url to $destination; returns '' on success, otherwise the reason. */
function download(string $url, string $destination): string
{
    if (str_starts_with($url, 'https://') && function_exists('curl_init')) {
        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            return "cannot write $destination";
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FAILONERROR => true,
        ]);

        $done = curl_exec($curl);
        $reason = $done ? '' : (curl_error($curl) ?: 'curl failed');
        fclose($handle);

        return $reason;
    }

    if (str_starts_with($url, 'https://') && (getenv('HTTPS_PROXY') || getenv('https_proxy'))) {
        fwrite(STDERR, "PHPStan phar: a proxy is configured but ext-curl is missing, and PHP's streams ignore it; install ext-curl.\n");
    }

    $context = stream_context_create([
        'http' => ['timeout' => 120, 'follow_location' => 1, 'max_redirects' => 3],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    error_clear_last();

    return @copy($url, $destination, $context) ? '' : (error_get_last()['message'] ?? 'copy failed');
}

echo 'PHPStan phar: fetching '.$version.' from '.(parse_url($url, PHP_URL_HOST) ?: 'a local file')."\n";

// Only a failed transfer is tried again; a file that arrives and does not match the pin is final.
$attempts = str_starts_with($url, 'https://') ? 3 : 1;
$reason = '';

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    $reason = download($url, $temporary);

    if ($reason === '') {
        break;
    }

    @unlink($temporary);

    if ($attempt < $attempts) {
        fwrite(STDERR, "PHPStan phar: attempt $attempt failed ($reason); trying again\n");
        sleep(2 * $attempt);
    }
}

if ($reason !== '') {
    fwrite(STDERR, "PHPStan phar: download failed for $url ($reason)\n");

    exit(1);
}

$actual = is_file($temporary) ? (string) hash_file('sha256', $temporary) : '';

if (! hash_equals($expected, $actual)) {
    @unlink($temporary);

    fwrite(STDERR, "PHPStan phar: checksum mismatch for $url\n  expected $expected\n  actual   $actual\n");

    exit(1);
}

if (! rename($temporary, $target)) {
    @unlink($temporary);

    fwrite(STDERR, "PHPStan phar: cannot move the verified file to $target\n");

    exit(1);
}

@chmod($target, 0755);

echo "PHPStan phar: tools/phpstan.phar is $version (checksum verified)\n";
