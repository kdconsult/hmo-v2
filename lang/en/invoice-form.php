<?php

return [
    'date_of_supply' => 'Date of supply',
    'date_of_supply_hint' => 'Date of the chargeable event. Leave blank if same as date of issue.',
    'late_issuance_title' => 'Late issuance',
    'late_issuance_body' => 'This invoice is issued more than 5 days after the chargeable event (чл. 113, ал. 4 ЗДДС). Review the dates before confirming.',
    'domestic_exempt_toggle' => 'Domestic VAT exemption',
    'domestic_exempt_hint' => 'Mark this invoice as exempt under a specific ЗДДС article (39–49).',
    'exemption_article' => 'Exemption article',
    'exempt_non_registered_tenant' => 'Exempt — tenant is not VAT-registered.',
    'triggering_event_date' => 'Triggering event date',
    'triggering_event_date_hint' => 'Date of the event prompting this correction (return, price change, cancellation). Defaults to today.',
    'note_late_issuance_title' => 'Late credit/debit note',
    'note_late_issuance_body' => 'This note is issued more than 5 days after the triggering event (чл. 115 ЗДДС). Review the dates before confirming.',
    'vat_treatment_inherited' => 'VAT treatment inherited from parent invoice',
    'vat_treatment_inherited_hint' => 'This note inherits the parent invoice\'s VAT scenario. Current partner or tenant VAT status does not affect this note.',
];
