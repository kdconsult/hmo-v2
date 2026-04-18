@php
    use App\Enums\VatScenario;
    use App\Models\VatLegalReference;

    $tenantCountry = tenancy()->tenant?->country_code ?? '';
    $legalRef = null;

    if (in_array($vat_scenario, [
        VatScenario::Exempt,
        VatScenario::DomesticExempt,
        VatScenario::EuB2bReverseCharge,
        VatScenario::NonEuExport,
    ], true)) {
        try {
            $legalRef = VatLegalReference::resolve(
                $tenantCountry,
                $vat_scenario->value,
                $vat_scenario_sub_code ?? 'default',
            );
        } catch (\DomainException) {
            $legalRef = null;
        }
    }

    $showViesRow = $is_reverse_charge && filled($vies_request_id);
@endphp
@if($is_reverse_charge || $legalRef)
    <div class="meta-box">
        <table class="meta">
            @if($is_reverse_charge)
                <tr>
                    <td class="meta-label">{{ __('invoice-pdf.vat_treatment') }}:</td>
                    <td class="meta-value">{{ __('invoice-pdf.reverse_charge') }}</td>
                </tr>
            @endif
            @if($showViesRow)
                <tr>
                    <td class="meta-label">{{ __('invoice-pdf.vies_consultation') }}:</td>
                    <td class="meta-value">
                        {{ $vies_request_id }}
                        @if($vies_checked_at) 
                            — {{ $vies_checked_at->format('d.m.Y H:i') }} UTC 
                        @endif
                    </td>
                </tr>
            @endif
            @if($legalRef)
                <tr>
                    <td class="meta-label">{{ __('invoice-pdf.legal_basis') }}:</td>
                    <td class="meta-value">
                        {{ $legalRef->legal_reference }}
                        @php
                            $desc = $legalRef->getTranslation('description', app()->getLocale(), false);
                        @endphp 
                        @if(filled($desc)) — {{ $desc }}@endif
                    </td>
                </tr>
            @endif
        </table>
    </div>
@endif
