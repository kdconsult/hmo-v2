<?php

return [
    'landlord_email' => env('HMO_LANDLORD_EMAIL', 'admin@example.com'),

    /**
     * The ID of the landlord's own tenant account.
     * Set this after creating your company tenant via the landlord panel.
     * When set, this tenant is protected from billing, suspension, and deletion,
     * and its company/bank details are used on all proforma invoices.
     */
    'landlord_tenant_id' => env('HMO_LANDLORD_TENANT_ID'),
];
