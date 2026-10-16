<?php

use Illuminate\Foundation\ViteException;
use Illuminate\Foundation\ViteFonts;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
 * R201 / ADR-028. laravel-vite-plugin fetches the Bunny fonts at build time and has no fallback: with
 * fonts.bunny.net unreachable (the Claude Code cloud sandbox) the build fails. VITE_FONTS_INLINE=0 swaps the fetch for
 * a fonts-manifest.json that imports Bunny's stylesheet by URL. Any other value, or none, is the self-hosted build CI,
 * local machines and rehearsal run. These tests read what scripts/vite-fonts.js would build and feed it to the
 * framework's own Vite::fonts(), the call every font consumer in the app makes; they never touch the network or
 * public/build. (The blocked-network build itself is a documented run: CYCLE-LOG 2026-10-16.)
 */

/**
 * Runs a Node snippet (or the helper's own CLI when $code is null) with VITE_FONTS_INLINE set to $flag, or unset for
 * null, so the suite passes wherever it runs, flag exported or not.
 *
 * @return array<string, mixed>
 */
function viteFontsNode(?string $flag, ?string $code = null): array
{
    $root = realpath(__DIR__.'/../..');
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_file($root.'/node_modules/laravel-vite-plugin/package.json')) {
        if (getenv('CI') !== false) {
            test()->fail('CI installs Node and runs `npm ci` before the tests; the fonts helper cannot run here.');
        }

        test()->markTestSkipped('Node or node_modules is not installed; the fonts helper cannot run.');
    }

    $command = $code === null
        ? [$node, $root.'/scripts/vite-fonts.js']
        : [$node, '--input-type=module', '-e', $code];

    // `false` unsets a variable the test process itself inherited.
    $process = new Process($command, $root, ['VITE_FONTS_INLINE' => $flag ?? false, 'NODE_OPTIONS' => false]);
    $process->run();

    if ($process->getExitCode() !== 0) {
        test()->fail('The fonts helper exited '.$process->getExitCode().': '.$process->getErrorOutput());
    }

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

const VITE_FONT_PINS = [
    'Schibsted Grotesk' => [400, 500, 700],
    'Quicksand' => [600],
    'IBM Plex Mono' => [400, 500],
];

/** The alias each pinned family has in the manifest (and in Vite::fonts('<alias>')). */
const VITE_FONT_ALIASES = ['schibsted-grotesk' => 'Schibsted Grotesk', 'quicksand' => 'Quicksand', 'ibm-plex-mono' => 'IBM Plex Mono'];

/**
 * Writes the flag-"0" manifest where Laravel reads a build's (public/build/fonts-manifest.json) under a throwaway
 * public path, and points the app there. Returns the directory so the caller can remove it.
 */
function byUrlFontsBuild(): string
{
    $public = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vite-fonts-'.bin2hex(random_bytes(6));
    mkdir($public.DIRECTORY_SEPARATOR.'build', 0777, true);
    file_put_contents($public.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'fonts-manifest.json', json_encode(viteFontsNode('0')['manifest']));

    app()->usePublicPath($public);

    return $public;
}

it('self-hosts the three Bunny families, and adds no plugin, when the flag is unset', function () {
    $built = viteFontsNode(null);

    expect($built['selfHost'])->toBeTrue()
        ->and($built['pluginNames'])->toBe([])
        ->and(array_column($built['definitions'], 'weights', 'family'))->toBe(VITE_FONT_PINS)
        ->and(array_unique(array_column($built['definitions'], 'provider')))->toBe(['bunny'])
        ->and(array_unique(array_column($built['definitions'], 'display')))->toBe(['swap'])
        ->and(array_unique(array_column($built['definitions'], 'preload')))->toBe([true]);
});

it('skips the Bunny fetch and adds exactly the by-URL plugin when the flag is "0"', function () {
    $built = viteFontsNode('0');

    expect($built['selfHost'])->toBeFalse()
        ->and($built['definitions'])->toBe([])
        ->and($built['pluginNames'])->toBe(['trustutor:fonts-by-url']);
});

it('treats only the exact string "0" as the flag', function (string $value) {
    $built = viteFontsNode($value);

    expect($built['selfHost'])->toBeTrue()
        ->and($built['definitions'])->toHaveCount(3)
        ->and($built['pluginNames'])->toBe([]);
})->with(['empty' => '', 'one' => '1', 'false' => 'false', 'padded' => ' 0', 'double' => '00', 'off' => 'off']);

it('writes a manifest the framework accepts and renders as one import of Bunny\'s stylesheet without an alias', function () {
    $manifest = viteFontsNode('0')['manifest'];
    $fonts = new ViteFonts;

    $fonts->ensureValidManifest($manifest);
    $style = $fonts->resolveStyleContent($manifest, null, 'build');

    expect($manifest['version'])->toBe(1)
        ->and($manifest['preloads'])->toBe([])
        ->and($style)->toStartWith('@import url("https://fonts.bunny.net/css2?')
        ->and($style)->toContain('&display=swap");');

    // Bunny's css2 API takes `family=Name+With+Plus:wght@400;500`, one `family=` per family, ascending weights.
    foreach (VITE_FONT_PINS as $family => $weights) {
        expect($style)->toContain('family='.str_replace(' ', '+', $family).':wght@'.implode(';', $weights));
    }

    expect(substr_count($style, 'family='))->toBe(count(VITE_FONT_PINS));
});

it('names the same aliases and CSS variables the self-hosted plugin does, so the flag changes how fonts load and not which', function () {
    $built = viteFontsNode(null);
    $families = $built['manifest']['families'];

    expect(array_column($built['definitions'], 'alias'))->toBe(array_keys(VITE_FONT_ALIASES))
        ->and(array_keys($families))->toBe(array_column($built['definitions'], 'alias'))
        ->and(array_column($families, 'variable'))->toBe(array_column($built['definitions'], 'variable'))
        ->and(array_column($families, 'family'))->toBe(array_column($built['definitions'], 'family'))
        ->and(array_keys($built['manifest']['style']['familyStyles']))->toBe(array_keys($families))
        ->and(array_keys($built['manifest']['style']['variables']))->toBe(array_keys($families))
        ->and($built['manifest'])->toBe(viteFontsNode('0')['manifest']);
});

it('renders Vite::fonts() with no alias as one stylesheet import plus the CSS variables, like the layout\'s @fonts', function () {
    $public = byUrlFontsBuild();

    try {
        $html = (string) Vite::fonts();
    } finally {
        File::deleteDirectory($public);
    }

    expect($html)->toStartWith("<style>\n@import url(\"https://fonts.bunny.net/css2?family=Schibsted+Grotesk:wght@400;500;700")
        ->and($html)->toEndWith("\n</style>")
        ->and(substr_count($html, 'family='))->toBe(3)
        ->and($html)->not->toContain('<link');

    foreach (VITE_FONT_ALIASES as $alias => $family) {
        expect($html)->toContain("--font-{$alias}: \"{$family}\";");
    }
});

it('renders Vite::fonts(<alias>) for every alias, the call the Filament admin head makes on every admin page', function (string $alias, string $family) {
    $public = byUrlFontsBuild();

    try {
        $html = (string) Vite::fonts($alias);
    } finally {
        File::deleteDirectory($public);
    }

    $weights = implode(';', VITE_FONT_PINS[$family]);

    expect($html)->toStartWith("<style>\n@import url(\"https://fonts.bunny.net/css2?family=".str_replace(' ', '+', $family).":wght@{$weights}&display=swap\");")
        ->and(substr_count($html, 'family='))->toBe(1)
        ->and($html)->toContain("--font-{$alias}: \"{$family}\";")
        ->and($html)->not->toContain('<link');

    // Only the asked-for family's variable: the filter must not leak the other two.
    expect(substr_count($html, '--font-'))->toBe(1);
})->with(array_map(fn (string $family, string $alias) => [$alias, $family], VITE_FONT_ALIASES, array_keys(VITE_FONT_ALIASES)));

it('still refuses an alias the manifest does not carry, so the alias tests above can fail', function () {
    $public = byUrlFontsBuild();

    try {
        expect(fn () => Vite::fonts('no-such-font'))->toThrow(ViteException::class, 'Font alias [no-such-font] is not defined');
    } finally {
        File::deleteDirectory($public);
    }
});

it('covers every font consumer in app/ and resources/views: each alias they ask for is in the manifest', function () {
    $root = realpath(__DIR__.'/../..');
    $aliases = [];
    $aliased = 0;
    $unaliased = 0;

    foreach ((new Finder)->files()->in([$root.'/app', $root.'/resources/views'])->name('*.php') as $file) {
        $source = $file->getContents();

        preg_match_all('/Vite::fonts\(([^)]*)\)/', $source, $facade);
        preg_match_all('/@fonts\b(?:\(([^)]*)\))?/', $source, $directive);

        foreach (array_merge($facade[1], $directive[1]) as $arguments) {
            preg_match_all('/[\'"]([a-z0-9-]+)[\'"]/', $arguments, $named);

            if ($named[1] === []) {
                $unaliased++;
            } else {
                $aliased++;
                array_push($aliases, ...$named[1]);
            }
        }
    }

    // Both kinds exist today (the layout's `@fonts`, the admin panel's `Vite::fonts('schibsted-grotesk')`); if the scan
    // stops finding them it has gone blind, not clean.
    expect($unaliased)->toBeGreaterThan(0)
        ->and($aliased)->toBeGreaterThan(0)
        ->and($aliases)->toContain('schibsted-grotesk')
        ->and(array_diff($aliases, array_keys(VITE_FONT_ALIASES)))->toBe([]);
});

it('emits fonts-manifest.json into the build only for a client build, only with the flag', function () {
    $contract = viteFontsNode('0', <<<'JS'
        import { fontsByUrlPlugins, fontsByUrlManifest } from './scripts/vite-fonts.js';

        const [plugin] = fontsByUrlPlugins();
        const emitted = [];
        plugin.generateBundle.call({ emitFile: (file) => emitted.push(file) });

        const applies = (config, command) => plugin.apply(config, { command });

        process.stdout.write(JSON.stringify({
            emitted,
            expected: fontsByUrlManifest(),
            client_build: applies({}, 'build'),
            ssr_build: applies({ build: { ssr: true } }, 'build'),
            dev_server: applies({}, 'serve'),
            self_hosted: fontsByUrlPlugins({}).length,
        }));
        JS);

    expect($contract['emitted'])->toHaveCount(1)
        ->and($contract['emitted'][0]['type'])->toBe('asset')
        ->and($contract['emitted'][0]['fileName'])->toBe('fonts-manifest.json')
        ->and(json_decode($contract['emitted'][0]['source'], true))->toBe($contract['expected'])
        ->and($contract['client_build'])->toBeTrue()
        ->and($contract['ssr_build'])->toBeFalse()
        ->and($contract['dev_server'])->toBeFalse()
        ->and($contract['self_hosted'])->toBe(0);
});

it('gives the Vite config the helper and no font list of its own', function () {
    $config = file_get_contents(__DIR__.'/../../vite.config.ts');

    expect($config)->toContain("from './scripts/vite-fonts.js'")
        ->and($config)->toContain('fonts: fontDefinitions(),')
        ->and($config)->toContain('...fontsByUrlPlugins(),')
        ->and($config)->not->toContain('bunny(');
});

it('keeps the layout on @fonts, which reads the manifest either mode writes', function () {
    $layout = file_get_contents(__DIR__.'/../../resources/views/app.blade.php');

    expect($layout)->toContain('@fonts')
        ->and($layout)->not->toContain('fonts.bunny.net');
});

it('serves every pinned family named in the stylesheet\'s font stacks', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    foreach (array_keys(VITE_FONT_PINS) as $family) {
        expect($css)->toContain("'{$family}'");
    }
});
