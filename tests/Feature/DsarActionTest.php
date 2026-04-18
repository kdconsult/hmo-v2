<?php

declare(strict_types=1);

use App\Events\DataSubjectRequestReceived;
use App\Filament\Resources\Partners\Pages\ViewPartner;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->tenant = Tenant::factory()->create(['country_code' => 'BG']);
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
    $this->actingAs($this->user);

    tenancy()->initialize($this->tenant);
    URL::defaults(['subdomain' => $this->tenant->slug]);
});

afterEach(function () {
    tenancy()->end();
});

test('data_subject_request action logs activity entry and dispatches DataSubjectRequestReceived event', function () {
    Event::fake([DataSubjectRequestReceived::class]);

    $partner = Partner::factory()->customer()->create();

    Livewire::test(ViewPartner::class, ['record' => $partner->id])
        ->callAction('data_subject_request')
        ->assertNotified('Data subject request logged');

    Event::assertDispatched(DataSubjectRequestReceived::class, fn ($event) => $event->partner->is($partner));

    $activity = Activity::where('subject_type', (new Partner)->getMorphClass())
        ->where('subject_id', $partner->id)
        ->where('description', 'data_subject_request')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->user->id);
});
