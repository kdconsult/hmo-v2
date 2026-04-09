<?php

return [
    'landlord_email' => env('HMO_LANDLORD_EMAIL', 'admin@example.com'),

    'bank_iban' => env('HMO_BANK_IBAN', ''),
    'bank_bic' => env('HMO_BANK_BIC', ''),
    'bank_name' => env('HMO_BANK_NAME', ''),

    'company_name' => env('HMO_COMPANY_NAME', ''),
    'company_vat' => env('HMO_COMPANY_VAT', ''),
    'company_eik' => env('HMO_COMPANY_EIK', ''),
    'company_address' => env('HMO_COMPANY_ADDRESS', ''),

    /**
     * The ID of the landlord's own tenant account.
     * Set this after creating your company tenant via the landlord panel.
     * When set, this tenant is protected from billing, suspension, and deletion.
     * When null, the system operates normally (no landlord tenant identified).
     *
     * Future: when tenant-side invoicing is built, this tenant will be used
     * to issue proforma invoices to other tenants via the invoicing system.
     */
    'landlord_tenant_id' => env('HMO_LANDLORD_TENANT_ID'),
];
