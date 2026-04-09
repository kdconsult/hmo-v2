<x-filament-panels::page>
    @php
        $tenant = tenancy()->tenant;
        $plan = $tenant?->plan;
        $status = $tenant?->subscription_status;
        $endDate = $tenant?->subscription_ends_at ?? $tenant?->trial_ends_at;
    @endphp

    <x-filament::section>
        <div class="space-y-6 text-center">
            <div class="flex justify-center">
                <x-filament::icon
                    icon="heroicon-o-exclamation-circle"
                    class="h-16 w-16 text-warning-500"
                />
            </div>

            <div>
                <h2 class="text-2xl font-bold tracking-tight">
                    Your subscription has expired
                </h2>
                <p class="mt-2 text-gray-500 dark:text-gray-400">
                    Access to this workspace is restricted until a valid subscription is active.
                </p>
            </div>

            <div class="mx-auto max-w-sm rounded-xl border border-gray-200 p-4 text-left dark:border-gray-700">
                <dl class="space-y-2 text-sm">
                    @if ($plan)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Plan</dt>
                            <dd class="font-medium">{{ $plan->name }}</dd>
                        </div>
                    @endif
                    @if ($status)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Status</dt>
                            <dd>
                                <x-filament::badge :color="$status->getColor()">
                                    {{ $status->getLabel() }}
                                </x-filament::badge>
                            </dd>
                        </div>
                    @endif
                    @if ($endDate)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Expired on</dt>
                            <dd class="font-medium">{{ $endDate->format('d M Y') }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Upgrade options --}}
            @if ($this->paidPlans->isNotEmpty())
                <div class="space-y-2">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Choose a plan to continue:</p>
                    <div class="flex flex-wrap justify-center gap-3">
                        @foreach ($this->paidPlans as $availablePlan)
                            <x-filament::button
                                wire:click="upgradeNow({{ $availablePlan->id }})"
                                wire:loading.attr="disabled"
                                color="primary"
                                icon="heroicon-o-arrow-up-circle"
                            >
                                {{ $availablePlan->name }} — €{{ number_format($availablePlan->price, 2) }}/{{ $availablePlan->billing_period }}
                            </x-filament::button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                <x-filament::button
                    tag="a"
                    :href="'mailto:' . config('hmo.landlord_email')"
                    color="gray"
                    icon="heroicon-o-envelope"
                >
                    Contact Support
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    :href="route('filament.admin.auth.logout')"
                    color="gray"
                    icon="heroicon-o-arrow-right-on-rectangle"
                >
                    Sign Out
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
