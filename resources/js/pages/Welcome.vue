<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicFooter from '@/components/PublicFooter.vue';
import PublicHeader from '@/components/PublicHeader.vue';
import { Button } from '@/components/ui/button';

defineProps<{
    content: {
        heroTitle: string;
        heroText: string;
        howItWorks: Array<{ title: string; html: string }>;
        faq: Array<{ question: string; html: string }>;
    };
}>();
</script>

<template>
    <Head title="Welcome" />
    <PublicHeader />

    <main class="mx-auto flex w-full max-w-5xl flex-col gap-12 p-4">
        <section class="grid gap-4 py-8">
            <h1 v-if="content.heroTitle" class="text-3xl font-semibold">{{ content.heroTitle }}</h1>
            <!-- Server-rendered markdown, raw HTML stripped (App\Support\Markdown). -->
            <div v-if="content.heroText" class="prose dark:prose-invert max-w-2xl" v-html="content.heroText" />
            <div class="flex flex-wrap gap-3">
                <Button as-child>
                    <Link href="/tutors">Find a tutor</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link href="/register">Create a parent account</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link href="/tutor/register">Become a tutor</Link>
                </Button>
            </div>
        </section>

        <section v-if="content.howItWorks.length" class="grid gap-4">
            <h2 class="text-xl font-semibold">How it works</h2>
            <ol class="grid gap-4 sm:grid-cols-3">
                <li v-for="(step, index) in content.howItWorks" :key="index" class="grid gap-1 rounded-xl border p-4">
                    <span class="text-muted-foreground text-xs">Step {{ index + 1 }}</span>
                    <h3 class="font-medium">{{ step.title }}</h3>
                    <div class="prose dark:prose-invert text-sm" v-html="step.html" />
                </li>
            </ol>
        </section>

        <section v-if="content.faq.length" class="grid gap-4">
            <h2 class="text-xl font-semibold">Frequently asked questions</h2>
            <div class="grid gap-3">
                <details v-for="(item, index) in content.faq" :key="index" class="rounded-xl border p-4">
                    <summary class="cursor-pointer font-medium">{{ item.question }}</summary>
                    <div class="prose dark:prose-invert mt-2 text-sm" v-html="item.html" />
                </details>
            </div>
        </section>
    </main>

    <PublicFooter />
</template>
