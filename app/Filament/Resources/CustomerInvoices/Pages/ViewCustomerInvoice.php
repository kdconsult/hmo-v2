<?php

namespace App\Filament\Resources\CustomerInvoices\Pages;

use App\DTOs\ManualOverrideData;
use App\Enums\DocumentStatus;
use App\Enums\ReverseChargeOverrideReason;
use App\Enums\VatScenario;
use App\Enums\VatStatus;
use App\Enums\ViesResult;
use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use App\Filament\Resources\CustomerDebitNotes\CustomerDebitNoteResource;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Services\CustomerInvoiceService;
use App\Services\PdfTemplateResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

class ViewCustomerInvoice extends ViewRecord
{
    protected static string $resource = CustomerInvoiceResource::class;

    /** Result from the most recent runViesPreCheck() call; null before first check. */
    public ?array $viesPreCheckResult = null;

    /** True when VIES was unreachable — shows inline retry/fallback buttons. */
    public bool $viesUnavailable = false;

    /** Timestamp of the last VIES attempt (for retry cooldown UX). */
    public ?string $lastViesAttemptAt = null;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (CustomerInvoice $record): bool => $record->isEditable()),

            // ─── Primary confirmation trigger + modal ────────────────────────────
            // mountUsing runs the VIES pre-check before the modal opens.
            // Throwing Halt prevents the modal from rendering on failure/unavailable.
            // On success, $this->viesPreCheckResult is set so buildConfirmationSchema can use it.
            Action::make('confirm')
                ->label('Confirm Invoice')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (CustomerInvoice $record): bool => $record->status === DocumentStatus::Draft && ! $this->viesUnavailable)
                ->modalHeading('Confirm Invoice')
                ->modalSubmitActionLabel('Confirm Invoice')
                ->schema(fn (CustomerInvoice $record): array => $this->buildConfirmationSchema($record))
                ->mountUsing(function (CustomerInvoice $record): void {
                    // If retryVies already stored the result, skip the VIES call and open modal directly.
                    if ($this->viesPreCheckResult !== null) {
                        return;
                    }

                    try {
                        $viesResult = app(CustomerInvoiceService::class)->runViesPreCheck($record);

                        if ($viesResult['needed'] && isset($viesResult['result'])) {
                            if ($viesResult['result'] === 'cooldown') {
                                $retryAfter = $viesResult['retry_after']->diffForHumans();
                                Notification::make()
                                    ->title('VIES check on cooldown')
                                    ->body("The VIES service was checked very recently. Please retry {$retryAfter}.")
                                    ->warning()
                                    ->send();
                                throw new Halt;
                            }

                            if ($viesResult['result'] === ViesResult::Invalid) {
                                // Stage the result (with downgrade intent) so second click skips re-check (F-024).
                                $this->viesPreCheckResult = $viesResult;
                                Notification::make()
                                    ->title('VAT number no longer valid')
                                    ->body('VIES confirmed this partner\'s VAT number is invalid. Click Confirm Invoice to finalise — the partner\'s VAT status will be reset and reverse charge will not apply.')
                                    ->danger()
                                    ->send();
                                throw new Halt;
                            }

                            if ($viesResult['result'] === ViesResult::Unavailable) {
                                $this->viesUnavailable = true;
                                $this->lastViesAttemptAt = now()->toIso8601String();
                                Notification::make()
                                    ->title('VIES service unreachable')
                                    ->body('Could not reach the EU VIES service. You can retry, confirm with standard VAT, or (if authorised) confirm with reverse charge.')
                                    ->warning()
                                    ->send();
                                throw new Halt;
                            }
                        }

                        $this->viesPreCheckResult = $viesResult;
                    } catch (Halt $e) {
                        throw $e;
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Cannot confirm invoice')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        throw new Halt;
                    }
                })
                ->action(function (CustomerInvoice $record): void {
                    try {
                        $viesData = $this->viesPreCheckResult;
                        $storedViesData = ($viesData && isset($viesData['result']) && $viesData['result'] instanceof ViesResult)
                            ? $viesData
                            : null;

                        app(CustomerInvoiceService::class)->confirmWithScenario(
                            $record,
                            viesData: $storedViesData,
                            isDomesticExempt: $record->vat_scenario === VatScenario::DomesticExempt,
                            subCode: $record->vat_scenario_sub_code,
                        );

                        $this->viesPreCheckResult = null;
                        Notification::make()->title('Invoice confirmed')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Cannot confirm invoice')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            // ─── VIES unavailable: retry ─────────────────────────────────────────
            Action::make('retryVies')
                ->label('Retry VIES Check')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('info')
                ->visible(fn (): bool => $this->viesUnavailable)
                ->action(function (CustomerInvoice $record): void {
                    try {
                        $viesResult = app(CustomerInvoiceService::class)->runViesPreCheck($record);
                        $this->lastViesAttemptAt = now()->toIso8601String();

                        if (isset($viesResult['result'])) {
                            if ($viesResult['result'] === 'cooldown') {
                                $retryAfter = $viesResult['retry_after']->diffForHumans();
                                Notification::make()
                                    ->title('On cooldown')
                                    ->body("Retry {$retryAfter}.")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            if ($viesResult['result'] === ViesResult::Invalid) {
                                $this->viesUnavailable = false;
                                Notification::make()
                                    ->title('VAT number no longer valid')
                                    ->body('Partner VAT status has been reset. You can now confirm the invoice without reverse charge.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            if ($viesResult['result'] === ViesResult::Unavailable) {
                                Notification::make()
                                    ->title('VIES still unreachable')
                                    ->body('The service is still unavailable. Try again later or use one of the fallback confirmation options.')
                                    ->warning()
                                    ->send();

                                return;
                            }
                        }

                        // VIES now available — open confirmation modal.
                        // viesPreCheckResult is set first so mountUsing skips the re-check.
                        $this->viesUnavailable = false;
                        $this->viesPreCheckResult = $viesResult;
                        $this->mountAction('confirm');
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            // ─── VIES unavailable: confirm with standard VAT ──────────────────────
            Action::make('confirmWithVat')
                ->label('Confirm with VAT')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->visible(fn (): bool => $this->viesUnavailable)
                ->requiresConfirmation()
                ->modalHeading('Confirm with Standard VAT?')
                ->modalDescription('VIES is currently unreachable. Confirming now will apply standard VAT — reverse charge will NOT be used. This is safe when you are confident the reverse charge does not apply.')
                ->action(function (CustomerInvoice $record): void {
                    try {
                        app(CustomerInvoiceService::class)->confirmWithScenario(
                            $record,
                            viesData: [
                                'needed' => true,
                                'result' => ViesResult::Unavailable,
                                'request_id' => null,
                                'checked_at' => now(),
                            ],
                            treatAsB2c: true,
                        );

                        $this->viesUnavailable = false;
                        Notification::make()->title('Invoice confirmed with standard VAT')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Cannot confirm invoice')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            // ─── VIES unavailable: confirm with reverse charge override ───────────
            Action::make('confirmWithReverseCharge')
                ->label('Confirm with Reverse Charge')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('danger')
                ->visible(function (CustomerInvoice $record): bool {
                    if (! $this->viesUnavailable) {
                        return false;
                    }
                    if (! auth()->user()?->can('override_reverse_charge_customer_invoice')) {
                        return false;
                    }
                    $partner = $record->partner;
                    $recencyDays = (int) config('vat-vies.reverse_charge_override_recency_days', 30);

                    return $partner?->vat_status === VatStatus::Confirmed
                        && $partner->vies_verified_at
                        && $partner->vies_verified_at->gt(now()->subDays($recencyDays));
                })
                ->modalHeading('Apply Reverse Charge without Current VIES Verification?')
                ->schema([
                    TextEntry::make('warning')
                        ->label('')
                        ->state(new HtmlString(
                            '<p class="text-warning-600 dark:text-warning-400">VIES is currently unreachable. Applying reverse charge without live VIES confirmation creates legal and audit risk. This action is recorded with your name and timestamp. Only proceed if you are certain this partner holds a valid EU VAT registration.</p>'
                        )),
                    Checkbox::make('acknowledged')
                        ->label('I acknowledge that I am applying reverse charge without current VIES verification and take responsibility for this decision.')
                        ->required()
                        ->accepted(),
                    Checkbox::make('alternative_proof_acknowledged')
                        ->label('I have obtained alternative proof of the customer\'s taxable status (e.g. VAT certificate) and will retain it for the statutory period (10 years per EU VAT Directive Art. 138).')
                        ->required()
                        ->accepted()
                        ->validationMessages(['accepted' => 'You must confirm you hold and will retain alternative proof before proceeding.']),
                ])
                ->action(function (array $data, CustomerInvoice $record): void {
                    try {
                        $record->reverse_charge_override_acknowledgement = true;

                        app(CustomerInvoiceService::class)->confirmWithScenario(
                            $record,
                            viesData: [
                                'needed' => true,
                                'result' => ViesResult::Unavailable,
                                'request_id' => null,
                                'checked_at' => now(),
                            ],
                            override: new ManualOverrideData(
                                userId: auth()->id(),
                                reason: ReverseChargeOverrideReason::ViesUnavailable,
                            ),
                        );

                        $this->viesUnavailable = false;
                        Notification::make()->title('Invoice confirmed with reverse charge override')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Cannot confirm invoice')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            // ─── Unchanged actions ────────────────────────────────────────────────
            Action::make('print_invoice')
                ->label('Print Invoice')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (CustomerInvoice $record): bool => $record->status === DocumentStatus::Confirmed)
                ->action(function (CustomerInvoice $record) {
                    $record->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate']);

                    $resolver = app(PdfTemplateResolver::class);
                    $view = $resolver->resolve('customer-invoice');
                    $locale = $resolver->localeFor('customer-invoice');

                    $previous = app()->getLocale();
                    app()->setLocale($locale);

                    try {
                        return response()->streamDownload(
                            function () use ($view, $record) {
                                $pdf = Pdf::loadView($view, ['invoice' => $record]);
                                echo $pdf->output();
                            },
                            "invoice-{$record->invoice_number}.pdf"
                        );
                    } finally {
                        app()->setLocale($previous);
                    }
                }),

            Action::make('create_credit_note')
                ->label('Create Credit Note')
                ->icon(Heroicon::OutlinedDocumentMinus)
                ->color('warning')
                ->visible(fn (CustomerInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Confirmed,
                    DocumentStatus::Paid,
                ]))
                ->url(fn (CustomerInvoice $record): string => CustomerCreditNoteResource::getUrl('create').'?customer_invoice_id='.$record->id),

            Action::make('create_debit_note')
                ->label('Create Debit Note')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('info')
                ->visible(fn (CustomerInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Confirmed,
                    DocumentStatus::Paid,
                ]))
                ->url(fn (CustomerInvoice $record): string => CustomerDebitNoteResource::getUrl('create').'?customer_invoice_id='.$record->id),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (CustomerInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (CustomerInvoice $record): void {
                    app(CustomerInvoiceService::class)->cancel($record);
                    Notification::make()->title('Invoice cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }

    /**
     * Build the read-only confirmation modal schema showing the VAT scenario,
     * VIES reference, and financial summary before the user finalises the confirmation.
     */
    private function buildConfirmationSchema(CustomerInvoice $record): array
    {
        $viesResult = $this->viesPreCheckResult;
        $tenantCountry = CompanySettings::get('company', 'country_code') ?? '';
        $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;

        $record->loadMissing('partner');

        // When VIES returned Invalid, the partner downgrade is staged but not yet applied.
        // Pass ignorePartnerVat: true so the preview reflects the post-downgrade B2C scenario (F-024).
        $ignorePartnerVatForPreview = isset($viesResult['result']) && $viesResult['result'] === ViesResult::Invalid;

        try {
            $scenario = $record->partner
                ? VatScenario::determine(
                    $record->partner,
                    $tenantCountry,
                    ignorePartnerVat: $ignorePartnerVatForPreview,
                    tenantIsVatRegistered: $tenantIsVatRegistered,
                    year: (int) ($record->issued_at?->year ?? now()->year),
                )
                : null;
        } catch (DomainException) {
            $scenario = null;
        }

        // Preview financials: zero-rated scenarios will wipe VAT at confirmation.
        $zeroRatedScenarios = [VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport, VatScenario::Exempt];
        if ($scenario && in_array($scenario, $zeroRatedScenarios, strict: true)) {
            $previewTax = '0.00';
            $previewTotal = bcsub((string) $record->subtotal, (string) $record->discount_amount, 2);
        } else {
            $previewTax = $record->tax_amount;
            $previewTotal = $record->total;
        }

        $scenarioColor = match ($scenario) {
            VatScenario::Domestic => 'success',
            VatScenario::EuB2bReverseCharge => 'info',
            VatScenario::EuB2cUnderThreshold, VatScenario::EuB2cOverThreshold => 'warning',
            VatScenario::NonEuExport, VatScenario::Exempt => 'gray',
            null => 'gray',
        };

        $sections = [];

        // ── VAT Treatment ─────────────────────────────────────────────────────
        if ($scenario) {
            $sections[] = Section::make('VAT Treatment')
                ->schema([
                    TextEntry::make('vat_scenario')
                        ->label('')
                        ->state($scenario->description())
                        ->badge()
                        ->color($scenarioColor)
                        ->columnSpanFull(),
                ]);
        }

        // ── VIES Verification (only when VIES returned a live valid result) ───
        if ($viesResult && ($viesResult['result'] ?? null) === ViesResult::Valid) {
            $requestId = $viesResult['request_id'] ?? '—';
            $checkedAt = $viesResult['checked_at'] ?? null;
            $timestamp = $checkedAt ? $checkedAt->format('Y-m-d H:i:s \U\T\C') : '—';

            $viesEntries = [
                TextEntry::make('vies_request_id')
                    ->label('Request ID')
                    ->state($requestId)
                    ->copyable(),
                TextEntry::make('vies_checked_at')
                    ->label('Verified At')
                    ->state($timestamp),
            ];

            if ($record->partner?->vat_number) {
                $viesEntries[] = TextEntry::make('partner_vat')
                    ->label('Partner VAT')
                    ->state($record->partner->vat_number)
                    ->badge()
                    ->color('success')
                    ->columnSpanFull();
            }

            $sections[] = Section::make('VIES Verification')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->columns(2)
                ->schema($viesEntries);
        }

        // ── Invoice Totals ────────────────────────────────────────────────────
        $sections[] = Section::make('Invoice Totals')
            ->schema([
                Grid::make(3)
                    ->schema([
                        TextEntry::make('preview_subtotal')
                            ->label('Subtotal')
                            ->state($record->subtotal)
                            ->money($record->currency_code),
                        TextEntry::make('preview_vat')
                            ->label('VAT')
                            ->state($previewTax)
                            ->money($record->currency_code),
                        TextEntry::make('preview_total')
                            ->label('Total')
                            ->state($previewTotal)
                            ->money($record->currency_code)
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->color('success'),
                    ]),
            ]);

        return $sections;
    }
}
