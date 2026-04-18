<?php

return [
    /*
     * How recent a partner's last VIES verification must be (in days) for the
     * "Confirm with Reverse Charge" override to be available when VIES is unreachable.
     * Below this threshold, the button is hidden and the user must confirm with standard VAT.
     */
    'reverse_charge_override_recency_days' => env('VAT_VIES_RC_OVERRIDE_RECENCY_DAYS', 30),
];
