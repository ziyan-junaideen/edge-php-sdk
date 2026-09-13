<?php

namespace Edge;

use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;

/** Read-only payment method details; card collection belongs in the browser integration. */
class PaymentMethod extends ApiResource
{
    use ListOperation;
    use ShowOperation;

    const TYPE = 'payment_methods';
    const SCHEMA = ['dates' => ['created_at', 'discarded_at', 'updated_at']];
    const FIELDS = [
        'nickname', 'card_bin', 'card_cvv_token', 'last_four', 'card_pan_token',
        'description', 'external_state', 'kind', 'expiry_month', 'expiry_year',
        'created_at', 'discarded_at', 'updated_at',
        'address', 'customer', 'payment_demands', 'merchant',
    ];
}
