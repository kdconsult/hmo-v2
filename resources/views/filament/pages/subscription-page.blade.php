<x-filament-panels::page>
    @php
        $tenant = $this->tenant;
        $plan = $tenant?->plan;
        $status = $tenant?->subscription_status;
        $endsAt = $tenant?->subscription_ends_at ?? $tenant?->trial_ends_at;
        $usage = $this->usage;
    @endphp

    {{-- Current plan summary --}}
    <x-filament::section heading="Current Plan">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Plan</p>
                <p class="mt-1 text-lg font-semibold">{{ $plan?->name ?? 'None' }}</p>
                @if ($plan && $plan->price > 0)
                    <p class="text-sm text-gray-500">€{{ number_format($plan->price, 2) }} / {{ $plan->billing_period }}</p>
                @elseif ($plan)
                    <p class="text-sm text-gray-500">Free</p>
                @endif
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
                <div class="mt-1">
                    @if ($status)
                        <x-filament::badge :color="$status->getColor()">{{ $status->getLabel() }}</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">Unknown</x-filament::badge>
                    @endif
                </div>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $tenant?->onTrial() ? 'Trial ends' : 'Renews / Expires' }}
                </p>
                <p class="mt-1 text-lg font-semibold">
                    {{ $endsAt ? $endsAt->format('d M Y') : '—' }}
                </p>
            </div>
        </div>
    </x-filament::section>

    {{-- Usage --}}
    <x-filament::section heading="Usage">
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            {{-- Users --}}
            <div>
                <div class="mb-1 flex justify-between text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-300">Users</span>
                    <span class="text-gray-500">
                        {{ $usage['users']['current'] }}
                        /
                        {{ $usage['users']['unlimited'] ? '∞' : $usage['users']['max'] }}
                    </span>
                </div>
                @if (! $usage['users']['unlimited'])
                    @php $pct = $usage['users']['max'] > 0 ? min(100, ($usage['users']['current'] / $usage['users']['max']) * 100) : 0; @endphp
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div
                            class="h-2 rounded-full {{ $pct >= 90 ? 'bg-danger-500' : ($pct >= 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                            style="width: {{ $pct }}%"
                        ></div>
                    </div>
                @else
                    <p class="text-xs text-gray-500">Unlimited</p>
                @endif
            </div>

            {{-- Documents --}}
            <div>
                <div class="mb-1 flex justify-between text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-300">Documents (this month)</span>
                    <span class="text-gray-500">
                        {{ $usage['documents']['current'] }}
                        /
                        {{ $usage['documents']['unlimited'] ? '∞' : $usage['documents']['max'] }}
                    </span>
                </div>
                @if (! $usage['documents']['unlimited'])
                    @php $pct = $usage['documents']['max'] > 0 ? min(100, ($usage['documents']['current'] / $usage['documents']['max']) * 100) : 0; @endphp
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div
                            class="h-2 rounded-full {{ $pct >= 90 ? 'bg-danger-500' : ($pct >= 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                            style="width: {{ $pct }}%"
                        ></div>
                    </div>
                @else
                    <p class="text-xs text-gray-500">Unlimited</p>
                @endif
            </div>
        </div>
    </x-filament::section>

    {{-- Upgrade options --}}
    @if ($this->availablePlans->isNotEmpty())
        <x-filament::section heading="Available Plans">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-{{ $this->availablePlans->count() }}">
                @foreach ($this->availablePlans as $availablePlan)
                    <div class="flex flex-col justify-between rounded-xl border p-4 {{ $plan?->id === $availablePlan->id ? 'border-primary-500 bg-primary-50 dark:bg-primary-950' : 'border-gray-200 dark:border-gray-700' }}">
                        <div>
                            <div class="flex items-center justify-between">
                                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $availablePlan->name }}</h3>
                                @if ($plan?->id === $availablePlan->id)
                                    <x-filament::badge color="primary">Current</x-filament::badge>
                                @endif
                            </div>
                            <p class="mt-1 text-2xl font-bold">€{{ number_format($availablePlan->price, 2) }}<span class="text-sm font-normal text-gray-500">/{{ $availablePlan->billing_period }}</span></p>
                            <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-400">
                                <li>{{ $availablePlan->max_users ? $availablePlan->max_users.' users' : 'Unlimited users' }}</li>
                                <li>{{ $availablePlan->max_documents ? number_format($availablePlan->max_documents).' docs/mo' : 'Unlimited docs' }}</li>
                            </ul>
                        </div>
                        @if ($plan?->id !== $availablePlan->id)
                            <div class="mt-4">
                                <x-filament::button
                                    wire:click="upgradeNow({{ $availablePlan->id }})"
                                    wire:loading.attr="disabled"
                                    color="primary"
                                    class="w-full"
                                >
                                    Upgrade to {{ $availablePlan->name }}
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Cancel subscription --}}
    @if ($status?->isAccessible() && $tenant?->hasActiveSubscription())
        <x-filament::section>
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-gray-900 dark:text-white">Cancel Subscription</p>
                    <p class="text-sm text-gray-500">You'll keep access until {{ $endsAt?->format('d M Y') ?? 'the end of the billing period' }}.</p>
                </div>
                <x-filament::button
                    wire:click="cancelSubscription"
                    wire:confirm="Are you sure you want to cancel your subscription?"
                    color="danger"
                    outlined
                >
                    Cancel Subscription
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
