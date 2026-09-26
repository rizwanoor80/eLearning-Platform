<script setup lang="ts">
/*
 * The TrusTutor lockups from docs/brand (R49, README §2). `lockup` is the horizontal wordmark | rule | tagline (C):
 * from 768px up the tagline sits beside the wordmark; below 768px it drops underneath, start-aligned, with no rule;
 * below 480px it is dropped and the wordmark stands alone. `wordmark` and `symbol` are the single marks. `dark`
 * forces the white knockout (a mark on a dark panel in the light scheme); without it the mark follows the colour
 * scheme, so the `.dark` scheme gets the knockout too. `decorative` blanks the alt text where the brand name is
 * already in the link text. Everything supplied is raster, so nothing is scaled above the sizes in public/brand
 * (the vector redraw is outstanding from the designer). The caller's class lands on the wrapper (size, visibility).
 */
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

withDefaults(
    defineProps<{
        variant?: 'lockup' | 'wordmark' | 'symbol';
        dark?: boolean;
        decorative?: boolean;
    }>(),
    { variant: 'lockup', dark: false, decorative: false },
);

const wordmarkColour = {
    src: '/brand/trustutor-wordmark-colour-600.png',
    set: '/brand/trustutor-wordmark-colour-600.png 600w, /brand/trustutor-wordmark-colour-1200.png 1200w',
};
const wordmarkWhite = '/brand/trustutor-wordmark-white-1200.png';
const symbolColour = '/brand/trustutor-symbol-colour-512.png';
const symbolWhite = '/brand/trustutor-symbol-white-512.png';
</script>

<template>
    <span
        v-if="variant === 'symbol'"
        :class="cn('inline-flex h-8 items-center', $attrs.class as string)"
        data-test="brand-symbol"
    >
        <img
            v-if="!dark"
            :src="symbolColour"
            :alt="decorative ? '' : 'TrusTutor'"
            class="h-full w-auto dark:hidden"
        />
        <img
            :src="symbolWhite"
            :alt="decorative ? '' : 'TrusTutor'"
            :class="dark ? 'h-full w-auto' : 'hidden h-full w-auto dark:block'"
        />
    </span>
    <span
        v-else-if="variant === 'wordmark'"
        :class="cn('inline-flex h-8 items-center', $attrs.class as string)"
        data-test="brand-wordmark"
    >
        <img
            v-if="!dark"
            :src="wordmarkColour.src"
            :srcset="wordmarkColour.set"
            sizes="143px"
            :alt="decorative ? '' : 'TrusTutor'"
            class="h-full w-auto dark:hidden"
        />
        <img
            :src="wordmarkWhite"
            :alt="decorative ? '' : 'TrusTutor'"
            :class="dark ? 'h-full w-auto' : 'hidden h-full w-auto dark:block'"
        />
    </span>
    <span
        v-else
        :class="
            cn(
                'flex items-center gap-4 max-md:flex-col max-md:items-start max-md:gap-2.5',
                $attrs.class as string,
            )
        "
        data-test="brand-lockup"
    >
        <span class="inline-flex h-8 items-center max-md:h-7">
            <img
                v-if="!dark"
                :src="wordmarkColour.src"
                :srcset="wordmarkColour.set"
                sizes="143px"
                alt="TrusTutor"
                class="h-full w-auto dark:hidden"
            />
            <img
                :src="wordmarkWhite"
                alt="TrusTutor"
                :class="
                    dark ? 'h-full w-auto' : 'hidden h-full w-auto dark:block'
                "
            />
        </span>
        <span
            class="hidden h-[27px] w-px flex-none md:block"
            :class="dark ? 'bg-white/30' : 'bg-[#e7d6d8] dark:bg-white/30'"
            aria-hidden="true"
        />
        <span
            class="font-tagline hidden text-[11px] leading-tight font-semibold whitespace-nowrap min-[480px]:block max-md:text-[10px]"
            :class="dark ? 'text-white' : 'text-maroon-700 dark:text-white'"
            data-test="brand-tagline"
            >Tutoring, Electrified.</span
        >
    </span>
</template>
