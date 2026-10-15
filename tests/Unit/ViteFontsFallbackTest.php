<?php

use Illuminate\Foundation\ViteFonts;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
 * R201 / ADR-028. laravel-vite-plugin fetches the Bunny fonts at build time and has no fallback: with
 * fonts.bunny.net unreachable (the Claude Code cloud sandbox) the build fails. VITE_FONTS_INLINE=0 swaps the fetch for
 * a fonts-manifest.json that imports Bunny's stylesheet by URL. Any other value, or none, is the self-hosted build CI,
 * local machines and rehearsal run. These tests read what scripts/vite-fonts.js would build; they never touch the
 * network or public/build. (The blocked-network build itself is a documented run: CYCLE-LOG 2026-10-16.)
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

it('writes a manifest the framework accepts and renders as an import of Bunny\'s stylesheet', function () {
    $manifest = viteFontsNode('0')['manifest'];
    $fonts = new ViteFonts;

    $fonts->ensureValidManifest($manifest);
    $style = $fonts->resolveStyleContent($manifest, null, 'build');

    expect($manifest['version'])->toBe(1)
        ->and($manifest['families'])->toBe([])
        ->and($manifest['preloads'])->toBe([])
        ->and($style)->toStartWith('@import url("https://fonts.bunny.net/css2?')
        ->and($style)->toEndWith('&display=swap");');

    // Bunny's css2 API takes `family=Name+With+Plus:wght@400;500`, one `family=` per family, ascending weights.
    foreach (VITE_FONT_PINS as $family => $weights) {
        expect($style)->toContain('family='.str_replace(' ', '+', $family).':wght@'.implode(';', $weights));
    }

    expect(substr_count($style, 'family='))->toBe(count(VITE_FONT_PINS));
});

it('builds the same manifest and URL in both modes, so the flag changes how fonts load and not which', function () {
    expect(viteFontsNode(null)['manifest'])->toBe(viteFontsNode('0')['manifest'])
        ->and(viteFontsNode(null)['url'])->toBe(viteFontsNode('0')['url']);
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
