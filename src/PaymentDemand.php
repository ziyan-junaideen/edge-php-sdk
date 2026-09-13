<?php

namespace Edge;

use Edge\Operations\ConfirmOperation;
use Edge\Operations\CreateOperation;
use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;
use Edge\Operations\UpdateOperation;

/** Payment demand details and explicit server confirmation. */
class PaymentDemand extends ApiResource
{
    use ListOperation;
    use ShowOperation;
    use CreateOperation;
    use UpdateOperation;
    use ConfirmOperation;

    const TYPE = 'payment_demands';
    const SCHEMA = [
        'dates' => ['created_at', 'updated_at', 'succeeded_at'],
        'money' => [
            'amount' => ['amount_cents', 'amount_currency'],
            'fee' => ['fee_cents', 'amount_currency'],
        ],
    ];
    const FIELDS = [
        'processor_state', 'succeeded_at', 'email_receipt', 'created_at', 'updated_at',
        'confirmed', 'fee_cents', 'amount_cents', 'amount_currency', 'description',
        'threeds_version', 'threeds_cryptogram', 'threeds_status', 'eci',
        'directory_transaction_eid', 'acs_transaction_eid', 'payer_timezone',
        'idempotency_key', 'capture_method', 'cvc2_check', 'address_line1_verification',
        'postal_code_verification', 'discount_cents', 'purchase_reference', 'purchase_kind',
        'shipping_detail', 'tax_detail', 'line_items',
        'merchant', 'buyer', 'payer', 'receiver', 'payment_subscription',
        'payment_method', 'billing_address', 'shipping_address',
    ];
}
