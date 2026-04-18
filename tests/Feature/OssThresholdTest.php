<?php

declare(strict_types=1);

use App\Events\OssThresholdExceeded;
use App\Filament\Resources\CustomerInvoices\Pages\CreateCustomerInvoice;
use App\Filament\Widgets\OssThresholdWidget;
use App\Models\EuOssAccumulation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->tenant = Tenant::factory()->vatRegistered()->create(['country_code' => 'BG']);
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
    $this->actingAs($this->user);

    tenancy()->initialize($this->tenant);
    URL::defaults(['subdomain' => $this->tenant->slug]);
});

afterEach(function () {
    tenancy()->end();
});

// ─── Widget ──────────────────────────────────────────────────────────────────

test('OssThresholdWidget shows stat with current accumulation', function () {
    EuOssAccumulation::accumulate('DE', (int) now()->year, 3000.0);

    Livewire::test(OssThresholdWidget::class)
        ->assertSee('€3,000.00')
        ->assertSee('30.0%');
});

test('OssThresholdWidget shows 85% when accumulation is above 80%', function () {
    EuOssAccumulation::accumulate('DE', (int) now()->year, 8500.0);

    Livewire::test(OssThresholdWidget::class)
        ->assertSee('€8,500.00')
        ->assertSee('85.0%');
});

// ─── Invoice form banner ──────────────────────────────────────────────────────

test('invoice form shows OSS threshold callout when accumulation exceeds 80%', function () {
    EuOssAccumulation::accumulate('DE', (int) now()->year, 9000.0);

    Livewire::test(CreateCustomerInvoice::class)
        ->assertSee('EU OSS Threshold Warning')
        ->assertSee('€9,000.00');
});

test('invoice form does not show OSS threshold callout when accumulation is below 80%', function () {
    EuOssAccumulation::accumulate('DE', (int) now()->year, 500.0);

    Livewire::test(CreateCustomerInvoice::class)
        ->assertDontSee('EU OSS Threshold Warning');
});

// ─── First-crossing event ─────────────────────────────────────────────────────

test('OssThresholdExceeded event fires only on first threshold crossing per year', function () {
    Event::fake([OssThresholdExceeded::class]);

    // Below threshold — no event.
    EuOssAccumulation::accumulate('DE', (int) now()->year, 9000.0);
    Event::assertNotDispatched(OssThresholdExceeded::class);

    // Crosses threshold — event dispatched once.
    EuOssAccumulation::accumulate('FR', (int) now()->year, 2000.0);
    Event::assertDispatched(OssThresholdExceeded::class, fn ($e) => $e->year === (int) now()->year);

    // Already exceeded — no second event.
    Event::fake([OssThresholdExceeded::class]);
    EuOssAccumulation::accumulate('DE', (int) now()->year, 500.0);
    Event::assertNotDispatched(OssThresholdExceeded::class);
});
