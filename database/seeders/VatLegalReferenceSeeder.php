<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\VatLegalReference;
use Illuminate\Database\Seeder;

/**
 * Seeds 16 Bulgarian VAT legal references used on invoice / credit-note / debit-note
 * PDFs for zero-rate, exempt, and reverse-charge scenarios.
 *
 *  1x Exempt (чл. 113, ал. 9 ЗДДС)
 * 11x DomesticExempt (чл. 39–49 ЗДДС)
 *  2x EU B2B reverse charge (goods / services split)
 *  2x Non-EU export (goods / services split)
 *
 * Idempotent via updateOrCreate on the unique (country, scenario, sub_code) tuple.
 *
 * Per-MS expansion: each new tenant country needs its own seed set. Backlog item.
 */
class VatLegalReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // 1. Exempt — non-VAT-registered tenant (issued supplies bear this notice)
            ['BG', 'exempt', 'default', 'чл. 113, ал. 9 ЗДДС',
                ['bg' => 'Доставки от лице, което не е регистрирано по ЗДДС', 'en' => 'Supply by a person not registered under VAT Act'],
                true, 0],

            // 2..12. DomesticExempt — BG Art. 39..49 (11 rows)
            ['BG', 'domestic_exempt', 'art_39', 'чл. 39 ЗДДС',
                ['bg' => 'Доставки, свързани със здравеопазване', 'en' => 'Healthcare-related supplies'],
                true, 10],
            ['BG', 'domestic_exempt', 'art_40', 'чл. 40 ЗДДС',
                ['bg' => 'Доставки, свързани със социални грижи и осигуряване', 'en' => 'Social care and social security supplies'],
                false, 20],
            ['BG', 'domestic_exempt', 'art_41', 'чл. 41 ЗДДС',
                ['bg' => 'Доставки, свързани с образование, спорт или физическо възпитание', 'en' => 'Education, sport and physical education supplies'],
                false, 30],
            ['BG', 'domestic_exempt', 'art_42', 'чл. 42 ЗДДС',
                ['bg' => 'Доставки, свързани с култура', 'en' => 'Cultural supplies'],
                false, 40],
            ['BG', 'domestic_exempt', 'art_43', 'чл. 43 ЗДДС',
                ['bg' => 'Доставки, свързани с вероизповедания', 'en' => 'Religious supplies'],
                false, 50],
            ['BG', 'domestic_exempt', 'art_44', 'чл. 44 ЗДДС',
                ['bg' => 'Доставки с нестопански характер', 'en' => 'Non-profit supplies'],
                false, 60],
            ['BG', 'domestic_exempt', 'art_45', 'чл. 45 ЗДДС',
                ['bg' => 'Доставка, свързана със земя и сгради', 'en' => 'Supply of land and buildings'],
                false, 70],
            ['BG', 'domestic_exempt', 'art_46', 'чл. 46 ЗДДС',
                ['bg' => 'Доставка на финансови услуги', 'en' => 'Financial services'],
                false, 80],
            ['BG', 'domestic_exempt', 'art_47', 'чл. 47 ЗДДС',
                ['bg' => 'Доставка на застрахователни услуги', 'en' => 'Insurance services'],
                false, 90],
            ['BG', 'domestic_exempt', 'art_48', 'чл. 48 ЗДДС',
                ['bg' => 'Доставка на хазартни игри', 'en' => 'Gambling services'],
                false, 100],
            ['BG', 'domestic_exempt', 'art_49', 'чл. 49 ЗДДС',
                ['bg' => 'Доставка на пощенски марки и пощенски услуги', 'en' => 'Postal stamps and postal services'],
                false, 110],

            // 13..14. EU B2B reverse charge — goods / services
            ['BG', 'eu_b2b_reverse_charge', 'goods', 'Art. 138 Directive 2006/112/EC',
                ['bg' => 'Вътреобщностна доставка на стоки (обратно начисляване)', 'en' => 'Intra-Community supply of goods (reverse charge)'],
                true, 10],
            ['BG', 'eu_b2b_reverse_charge', 'services', 'Art. 44 + 196 Directive 2006/112/EC',
                ['bg' => 'Доставка на услуги с място на изпълнение в държава-членка на получателя (обратно начисляване)', 'en' => 'Services with place of supply in recipient Member State (reverse charge)'],
                false, 20],

            // 15..16. Non-EU export — goods / services
            ['BG', 'non_eu_export', 'goods', 'Art. 146 Directive 2006/112/EC',
                ['bg' => 'Износ на стоки извън ЕС (нулева ставка)', 'en' => 'Export of goods outside the EU (zero-rated)'],
                true, 10],
            ['BG', 'non_eu_export', 'services', 'Art. 44 Directive 2006/112/EC (outside scope of EU VAT)',
                ['bg' => 'Услуги към получател извън ЕС — извън обхвата на ДДС', 'en' => 'Services to a non-EU customer — outside the scope of EU VAT'],
                false, 20],
        ];

        foreach ($rows as [$country, $scenario, $subCode, $legalRef, $description, $isDefault, $sortOrder]) {
            VatLegalReference::updateOrCreate(
                ['country_code' => $country, 'vat_scenario' => $scenario, 'sub_code' => $subCode],
                [
                    'legal_reference' => $legalRef,
                    'description' => $description,
                    'is_default' => $isDefault,
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
