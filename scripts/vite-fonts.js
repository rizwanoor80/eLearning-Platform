// The Vite build's web fonts (CP8 16b, R201, ADR-028).
//
// Default: the three families below are fetched from Bunny at build time and self-hosted by
// laravel-vite-plugin/fonts. That path is what CI, local machines and rehearsal run, and it is
// the only path that exists unless VITE_FONTS_INLINE is exactly '0'.
//
// VITE_FONTS_INLINE=0 is for a build that cannot reach fonts.bunny.net (the Claude Code cloud
// sandbox). It skips the fetch and instead writes a fonts-manifest.json whose inline style is an
// @import of Bunny's stylesheet, so `@fonts` in the layout still renders the fonts, by URL, and no
// font file lands in public/build. It is a switch, not a fallback: a build that cannot reach Bunny
// WITHOUT the flag fails loudly, so a deploy never silently changes from self-hosted to by-URL.
//
// The flag is read from the shell environment: Vite does not load .env files for its own config.
// Under `vp dev` the flag simply fetches nothing: there is no dev manifest, `@fonts` renders empty
// and the pages use the system stacks in resources/css/app.css.
//
// `node scripts/vite-fonts.js` prints what the current environment would build (the tests read it).
import { pathToFileURL } from 'node:url';
import { bunny } from 'laravel-vite-plugin/fonts';

/** The one list of families; both modes are derived from it. */
export const FONT_FAMILIES = [
    { family: 'Schibsted Grotesk', weights: [400, 500, 700] },
    { family: 'Quicksand', weights: [600] },
    { family: 'IBM Plex Mono', weights: [400, 500] },
];

/** True unless VITE_FONTS_INLINE is exactly the string '0' (unset, empty, '1', 'false' all self-host). */
export function selfHostFonts(env = process.env) {
    return env.VITE_FONTS_INLINE !== '0';
}

/** What laravel-vite-plugin itself makes of a family (alias, CSS variable): both modes read it, so they cannot drift. */
function pluginDefinition({ family, weights }) {
    return bunny(family, { weights });
}

/** The `fonts` option for laravel-vite-plugin: the Bunny definitions, or none when the flag is '0'. */
export function fontDefinitions(env = process.env) {
    if (!selfHostFonts(env)) {
        return [];
    }

    return FONT_FAMILIES.map(pluginDefinition);
}

/** One css2 URL for the given families, in the shape Bunny's (and Google's) css2 API takes. */
export function bunnyStylesheetUrl(families = FONT_FAMILIES) {
    const params = families.map(
        ({ family, weights }) =>
            `family=${family.replace(/ /g, '+')}:wght@${[...weights].sort((a, b) => a - b).join(';')}`,
    );

    return `https://fonts.bunny.net/css2?${params.join('&')}&display=swap`;
}

function importRule(families) {
    return `@import url("${bunnyStylesheetUrl(families)}");`;
}

function variableDeclaration({ family, variable }) {
    return `${variable}: "${family}";`;
}

/**
 * The fonts-manifest.json (version 1) the framework's ViteFonts reads, with the same keys the self-hosted manifest has,
 * so every caller works in both modes: `@fonts` (no alias) renders `style.inline`, and `Vite::fonts('<alias>')` (the
 * Filament admin head) needs the alias under `families` and reads `style.familyStyles[alias]` plus `style.variables`.
 * No preloads and no font files: the browser fetches Bunny's stylesheet, and the fonts it names, itself.
 */
export function fontsByUrlManifest() {
    const families = {};
    const familyStyles = {};
    const variables = {};

    for (const entry of FONT_FAMILIES) {
        const { alias, family, variable, weights } = pluginDefinition(entry);

        families[alias] = {
            family,
            variable,
            variants: Object.fromEntries(
                [...weights]
                    .sort((a, b) => a - b)
                    .map((weight) => [`${weight}:normal`, { files: [] }]),
            ),
        };
        familyStyles[alias] = importRule([entry]);
        variables[alias] = variableDeclaration({ family, variable });
    }

    const declarations = Object.values(variables).map((line) => `  ${line}`);

    return {
        version: 1,
        style: {
            inline: [
                importRule(FONT_FAMILIES),
                `:root {\n${declarations.join('\n')}\n}`,
            ].join('\n\n'),
            familyStyles,
            variables,
        },
        preloads: [],
        families,
    };
}

/** Vite plugins for the flag: nothing by default; with '0', emit the by-URL manifest into the build. */
export function fontsByUrlPlugins(env = process.env) {
    if (selfHostFonts(env)) {
        return [];
    }

    return [
        {
            name: 'trustutor:fonts-by-url',
            apply: (config, { command, isSsrBuild }) =>
                command === 'build' && !config.build?.ssr && !isSsrBuild,
            generateBundle() {
                this.emitFile({
                    type: 'asset',
                    fileName: 'fonts-manifest.json',
                    source: JSON.stringify(fontsByUrlManifest(), null, 2),
                });
            },
        },
    ];
}

if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(process.argv[1]).href
) {
    process.stdout.write(
        JSON.stringify({
            selfHost: selfHostFonts(),
            definitions: fontDefinitions().map(
                ({
                    family,
                    alias,
                    variable,
                    provider,
                    weights,
                    styles,
                    subsets,
                    display,
                    preload,
                }) => ({
                    family,
                    alias,
                    variable,
                    provider,
                    weights,
                    styles,
                    subsets,
                    display,
                    preload,
                }),
            ),
            pluginNames: fontsByUrlPlugins().map((plugin) => plugin.name),
            url: bunnyStylesheetUrl(),
            manifest: fontsByUrlManifest(),
        }) + '\n',
    );
}
