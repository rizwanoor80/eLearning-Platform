<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Circle } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

// `href`: where this item goes (the parent checklist); null = cannot be actioned here; absent = the page-level `href`/`open` handling.
export type ChecklistItem = { key: string; label: string; step: string; done: boolean; href?: string | null };
export type ChecklistGroup = {
    key: string;
    title: string;
    hint: string;
    required: boolean;
    complete: boolean;
    items: ChecklistItem[];
};

const props = defineProps<{
    groups: ChecklistGroup[];
    // Condensed: one line per group with only what is still to do (the dashboard).
    condensed?: boolean;
    // On the onboarding page an item opens its section in place; elsewhere it is a link.
    href?: string;
    title?: string;
}>();

const emit = defineEmits<{ open: [step: string] }>();

const remaining = computed(() => props.groups.reduce((sum, group) => sum + group.items.filter((item) => !item.done).length, 0));
const visibleItems = (group: ChecklistGroup) => (props.condensed ? group.items.filter((item) => !item.done) : group.items);
</script>

<template>
    <div class="grid gap-4 rounded-xl border p-4" data-test="onboarding-checklist">
        <div class="flex items-center justify-between gap-2">
            <h2 class="font-semibold">{{ title ?? (condensed ? 'Finish setting up your profile' : 'Your profile checklist') }}</h2>
            <span v-if="condensed" class="text-muted-foreground text-xs">{{ remaining }} to go</span>
        </div>

        <section v-for="group in groups" v-show="!condensed || visibleItems(group).length > 0" :key="group.key" class="grid gap-2" :data-test="`checklist-group-${group.key}`">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-medium">{{ group.title }}</h3>
                <Badge :variant="group.required ? 'default' : 'secondary'">{{ group.required ? 'Required' : 'Optional' }}</Badge>
                <span v-if="group.complete" class="text-xs text-green-700">All done</span>
            </div>
            <p v-if="!condensed" class="text-muted-foreground text-xs">{{ group.hint }}</p>
            <ul class="grid gap-1">
                <li v-for="item in visibleItems(group)" :key="item.key" class="flex items-center gap-2 text-sm" :data-done="item.done ? 'true' : 'false'">
                    <Check v-if="item.done" class="size-4 shrink-0 text-green-700" aria-label="Done" />
                    <Circle v-else class="text-muted-foreground size-4 shrink-0" aria-label="Still to do" />
                    <Link v-if="item.href" :href="item.href" class="underline-offset-4 hover:underline">{{ item.label }}</Link>
                    <span v-else-if="item.href === null">{{ item.label }}</span>
                    <Link v-else-if="href" :href="href" class="underline-offset-4 hover:underline">{{ item.label }}</Link>
                    <button v-else type="button" class="text-start underline-offset-4 hover:underline" @click="emit('open', item.step)">
                        {{ item.label }}
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
