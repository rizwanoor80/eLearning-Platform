<x-filament-panels::page>
    @php
        $token = $this->activeToken();
        $endpoint = url('/ops/status');
    @endphp

    @if ($revealedToken)
        <x-filament::section>
            <x-slot name="heading">Copy the status token now</x-slot>
            <x-slot name="description">It is shown this once and cannot be shown again. Leaving this page or pressing the button below hides it for good.</x-slot>

            <code class="block break-all rounded-lg bg-gray-100 p-3 font-mono text-sm dark:bg-white/5" data-testid="revealed-token">{{ $revealedToken }}</code>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                Put it in the cloud environment as <code>TRUSTUTOR_STATUS_TOKEN</code>. It only lets a caller read the status document.
            </p>

            <div class="mt-4">
                <x-filament::button color="gray" wire:click="dismissToken">I have copied it</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Status token</x-slot>
        <x-slot name="description">Opens <code>GET {{ $endpoint }}</code>, the operator status document: versions, queue and scheduler health, the ledger check, video webhook counts. No secrets, rows or user data.</x-slot>

        @if ($token)
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-gray-950 dark:text-white">State</dt>
                    <dd class="text-gray-600 dark:text-gray-400">Active since {{ $token->created_at->utc()->format('Y-m-d H:i') }} UTC</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-950 dark:text-white">Last used</dt>
                    <dd class="text-gray-600 dark:text-gray-400">{{ $token->last_used_at ? $token->last_used_at->utc()->format('Y-m-d H:i').' UTC' : 'Never' }}</dd>
                </div>
            </dl>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">There is no active token, so the endpoint answers 404 to everyone. Use “Generate token” above.</p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Allow-list</x-slot>

        <form wire:submit="saveAllowList">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit">Save allow-list</x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Using it</x-slot>

        <pre class="overflow-x-auto rounded-lg bg-gray-100 p-3 text-sm dark:bg-white/5"><code>curl -s -H "Authorization: Bearer $TRUSTUTOR_STATUS_TOKEN" {{ $endpoint }}</code></pre>
    </x-filament::section>
</x-filament-panels::page>
