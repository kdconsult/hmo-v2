<div class="bg-white shadow rounded-lg p-8">
    {{-- Step indicator --}}
    <div class="flex items-center justify-between mb-8">
        @foreach ([1 => 'Account', 2 => 'Organization', 3 => 'VAT', 4 => 'Plan'] as $s => $label)
            <div class="flex items-center {{ !$loop->last ? 'flex-1' : '' }}">
                <div
                    class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold
                    {{ $step >= $s ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                    {{ $s }}
                </div>
                <span class="ml-2 text-sm font-medium {{ $step >= $s ? 'text-blue-600' : 'text-gray-400' }}">
                    {{ $label }}
                </span>
                @if (!$loop->last)
                    <div class="flex-1 h-px mx-4 {{ $step > $s ? 'bg-blue-600' : 'bg-gray-200' }}"></div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Step 1: Account --}}
    @if ($step === 1)
        <h2 class="text-xl font-semibold text-gray-900 mb-6">Create your account</h2>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" wire:model="name"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2"
                    placeholder="Your name">
                @error('name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" wire:model="email"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2"
                    placeholder="you@company.com">
                @error('email')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" wire:model="password"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Confirm Password</label>
                <input type="password" wire:model="password_confirmation"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button wire:click="nextStep"
                class="px-6 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700">
                Next &rarr;
            </button>
        </div>
    @endif

    {{-- Step 2: Organization --}}
    @if ($step === 2)
        <h2 class="text-xl font-semibold text-gray-900 mb-6">Your organization</h2>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Company Name</label>
                <input type="text" wire:model.live="company_name"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2"
                    placeholder="Acme Ltd.">
                @error('company_name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Country</label>
                <select wire:model.live="country_code"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                    @foreach ($this->countries as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>
                @error('country_code')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>


            <div>
                <label class="block text-sm font-medium text-gray-700">Company Number / EIK *</label>
                <input type="text" wire:model="eik"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                @error('eik')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="rounded-md bg-blue-50 p-3 text-sm text-blue-700">
                <strong>Auto-configured:</strong> Currency: <strong>{{ $currency_code }}</strong>, Timezone:
                <strong>{{ $timezone }}</strong>
            </div>
        </div>

        <div class="mt-6 flex justify-between">
            <button wire:click="previousStep"
                class="px-6 py-2 border border-gray-300 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-50">
                &larr; Back
            </button>
            <button wire:click="nextStep"
                class="px-6 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700">
                Next &rarr;
            </button>
        </div>
    @endif

    {{-- Step 3: VAT Registration (optional) --}}
    @if ($step === 3)
        <h2 class="text-xl font-semibold text-gray-900 mb-2">VAT Registration <span class="text-gray-400 font-normal text-base">(optional)</span></h2>
        <p class="text-sm text-gray-500 mb-6">If your company is VAT-registered, verify your number via VIES now. You can also do this later in Company Settings.</p>

        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="isVatRegistered" id="isVatRegistered"
                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <label for="isVatRegistered" class="text-sm font-medium text-gray-700">My company is VAT-registered</label>
            </div>

            @if ($isVatRegistered)
                <div>
                    <label class="block text-sm font-medium text-gray-700">VAT Number</label>
                    <div class="mt-1 flex rounded-md shadow-sm">
                        <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-sm">
                            {{ \App\Support\EuCountries::vatPrefixForCountry($country_code) ?? $country_code }}
                        </span>
                        <input type="text" wire:model="vatLookup"
                            class="flex-1 block w-full rounded-none border-gray-300 focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2"
                            placeholder="123456789">
                        <button wire:click="checkVies" type="button"
                            class="inline-flex items-center px-4 py-2 border border-l-0 border-gray-300 rounded-r-md bg-gray-50 text-sm font-medium text-gray-700 hover:bg-gray-100">
                            Check VIES
                        </button>
                    </div>
                </div>

                @if ($vatCheckMessage)
                    @php
                        $alertClass = match($vatCheckType) {
                            'success' => 'bg-green-50 border-green-200 text-green-800',
                            'danger'  => 'bg-red-50 border-red-200 text-red-800',
                            default   => 'bg-amber-50 border-amber-200 text-amber-800',
                        };
                    @endphp
                    <div class="rounded-md border p-3 text-sm {{ $alertClass }}">
                        {{ $vatCheckMessage }}
                    </div>
                @endif
            @endif
        </div>

        <div class="mt-6 flex justify-between">
            <button wire:click="previousStep"
                class="px-6 py-2 border border-gray-300 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-50">
                &larr; Back
            </button>
            <button wire:click="nextStep"
                class="px-6 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700">
                Next &rarr;
            </button>
        </div>
    @endif

    {{-- Step 4: Plan --}}
    @if ($step === 4)
        <h2 class="text-xl font-semibold text-gray-900 mb-2">Choose your plan</h2>
        <p class="text-sm text-gray-500 mb-6">All plans include a 14-day free trial. No credit card required.</p>

        <div class="grid gap-4">
            @foreach ($this->plans as $plan)
                <label
                    class="relative flex cursor-pointer rounded-lg border p-4 focus:outline-none {{ $plan_id === $plan->id ? 'border-blue-600 bg-blue-50' : 'border-gray-200' }}">
                    <input type="radio" wire:model="plan_id" value="{{ $plan->id }}" class="sr-only">
                    <div class="flex flex-1 justify-between">
                        <div>
                            <span class="block text-sm font-semibold text-gray-900">{{ $plan->name }}</span>
                            <span class="block text-sm text-gray-500 mt-1">
                                @if ($plan->max_users)
                                    Up to {{ $plan->max_users }} users
                                @else
                                    Unlimited users
                                @endif
                                &bull;
                                @if ($plan->max_documents)
                                    {{ number_format($plan->max_documents) }} documents/month
                                @else
                                    Unlimited documents
                                @endif
                            </span>
                        </div>
                        <div class="text-right">
                            @if ($plan->isFree())
                                <span class="text-lg font-bold text-gray-900">Free</span>
                            @else
                                <span
                                    class="text-lg font-bold text-gray-900">&euro;{{ number_format($plan->price, 2) }}</span>
                                <span class="text-sm text-gray-500">/{{ $plan->billing_period }}</span>
                            @endif
                        </div>
                    </div>
                </label>
            @endforeach
        </div>

        @php $selectedPlan = $this->plans->firstWhere('id', $plan_id); @endphp
        @if ($selectedPlan && !$selectedPlan->isFree())
            <div class="mt-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                After your 14-day trial, you will receive a proforma invoice of
                <strong>&euro;{{ number_format($selectedPlan->price, 2) }}/{{ $selectedPlan->billing_period }}</strong>
                via email with bank transfer details.
            </div>
        @endif

        @error('plan_id')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="mt-6 flex justify-between">
            <button wire:click="previousStep"
                class="px-6 py-2 border border-gray-300 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-50">
                &larr; Back
            </button>
            <button wire:click="submit"
                class="px-6 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700">
                Create Account &amp; Start Trial
            </button>
        </div>
    @endif
</div>
