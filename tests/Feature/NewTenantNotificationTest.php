<?php

declare(strict_types=1);

use App\Livewire\RegisterTenant;
use App\Mail\NewTenantRegistered;
use App\Mail\WelcomeTenant;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\NewTenantRegisteredNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Plan::create([
        'name' => 'Free', 'slug' => 'free',
        'price' => 0, 'billing_period' => null,
        'max_users' => 2, 'max_documents' => 100,
        'is_active' => true, 'sort_order' => 0,
    ]);
});

test('landlord receives email when new tenant registers', function () {
    Mail::fake();
    Notification::fake();

    $landlord = User::factory()->create(['is_landlord' => true, 'email' => 'landlord@example.com']);

    Livewire::test(RegisterTenant::class)
        ->set('name', 'New Tenant')
        ->set('email', 'new@tenant.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'New Co')
        ->set('country_code', 'BG')
        ->set('eik', '444444444')
        ->call('nextStep')
        ->call('submit');

    Mail::assertSent(WelcomeTenant::class);
    Mail::assertSent(NewTenantRegistered::class, fn ($mail) => $mail->hasTo(config('hmo.landlord_email')));
});

test('landlord users receive database notification when new tenant registers', function () {
    Mail::fake();
    Notification::fake();

    $landlord = User::factory()->create(['is_landlord' => true]);
    $nonLandlord = User::factory()->create(['is_landlord' => false]);

    Livewire::test(RegisterTenant::class)
        ->set('name', 'Another Tenant')
        ->set('email', 'another@tenant.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'Another Co')
        ->set('country_code', 'BG')
        ->set('eik', '555555555')
        ->call('nextStep')
        ->call('submit');

    Notification::assertSentTo($landlord, NewTenantRegisteredNotification::class);
    Notification::assertNotSentTo($nonLandlord, NewTenantRegisteredNotification::class);
});
