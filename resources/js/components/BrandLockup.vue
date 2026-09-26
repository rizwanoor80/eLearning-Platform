<script setup lang="ts">
/*
 * The TrusTutor lockups from docs/brand (R49, README §2). `lockup` is the horizontal wordmark | rule | tagline (C):
 * from 768px up the tagline sits beside the wordmark; below 768px it drops underneath, start-aligned, with no rule;
 * below 480px it is dropped and the wordmark stands alone. `wordmark` and `symbol` are the single marks. `dark`
 * swaps in the white knockout for dark grounds. Everything supplied is raster, so nothing is scaled above the
 * sizes in public/brand (the vector redraw is outstanding from the designer).
 */
import { computed } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        variant?: 'lockup' | 'wordmark' | 'symbol';
        dark?: boolean;
    }>(),
    { variant: 'lockup', dark: false },
);

const wordmark = computed(() =>
    props.dark
        ? { src: '/brand/trustutor-wordmark-white-1200.png', set: '' }
        : {
              src: '/brand/trustutor-wordmark-colour-600.png',
              set: '/brand/trustutor-wordmark-colour-600.png 600w, /brand/trustutor-wordmark-colour-1200.png 1200w',
          },
);
const symbol = computed(() =>
    props.dark
        ? '/brand/trustutor-symbol-white-512.png'
        : '/brand/trustutor-symbol-colour-512.png',
);
</script>

<template>
    <img
        v-if="variant === 'symbol'"
        :src="symbol"
        alt="TrusTutor"
        :class="cn('h-8 w-auto', $attrs.class as string)"
        data-test="brand-symbol"
    />
    <img
        v-else-if="variant === 'wordmark'"
        :src="wordmark.src"
        :srcset="wordmark.set || undefined"
        sizes="143px"
        alt="TrusTutor"
        :class="cn('h-8 w-auto', $attrs.class as string)"
        data-test="brand-wordmark"
    />
    <span
        v-else
        :class="cn('flex items-center gap-4 max-md:flex-col max-md:items-start max-md:gap-2.5', $attrs.class as string)"
        data-test="brand-lockup"
    >
        <img
            :src="wordmark.src"
            :srcset="wordmark.set || undefined"
            sizes="143px"
            alt="TrusTutor"
            class="h-8 w-auto max-md:h-7"
        />
        <span
            class="hidden h-[27px] w-px flex-none md:block"
            :class="dark ? 'bg-white/30' : 'bg-[#e7d6d8]'"
            aria-hidden="true"
        />
        <span
            class="font-tagline hidden text-[11px] leading-tight font-semibold whitespace-nowrap min-[480px]:block max-md:text-[10px]"
            :class="dark ? 'text-white' : 'text-maroon-700'"
            data-test="brand-tagline"
            >Tutoring, Electrified.</span
        >
    </span>
</template>
