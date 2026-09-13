<?php

namespace Edge;

use Edge\Operations\ConfirmOperation;
use Edge\Operations\CreateOperation;
use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;
use Edge\Operations\UpdateOperation;

/** Recurring payment details and explicit server confirmation. */
class PaymentSubscription extends ApiResource
{
    use ListOperation;
    use ShowOperation;
    use CreateOperation;
    use UpdateOperation;
    use ConfirmOperation;

    const TYPE = 'payment_subscriptions';
    const SCHEMA = [
        'dates' => [
            'created_at', 'updated_at', 'billing_cycle_anchor_at', 'trial_end_at',
            'next_billing_at', 'current_period_start_at', 'current_period_end_at',
            'last_processed_at', 'last_active_at', 'last_paused_at', 'canceled_at',
        ],
        'money' => [
            'amount' => ['amount_cents', 'amount_currency'],
            'fee' => ['fee_cents', 'amount_currency'],
        ],
    ];
    const FIELDS = [
        'status', 'email_receipt', 'alert_receipt', 'created_at', 'updated_at',
        'confirmed', 'fee_cents', 'amount_cents', 'amount_currency', 'description',
        'threeds_version', 'threeds_cryptogram', 'threeds_status', 'eci',
        'directory_transaction_eid', 'acs_transaction_eid', 'payer_timezone',
        'idempotency_key', 'cvc2_check', 'address_line1_verification',
        'postal_code_verification', 'discount_cents', 'purchase_reference', 'purchase_kind',
        'shipping_detail', 'tax_detail', 'line_items',
        'slug', 'billing_scheme', 'billing_period', 'proration_behavior',
        'canceled_at_period_end', 'subscription_notification_email',
        'billing_cycle_anchor_at', 'trial_end_at', 'next_billing_at',
        'current_period_start_at', 'current_period_end_at', 'last_processed_at',
        'last_active_at', 'last_paused_at', 'canceled_at',
        'merchant', 'buyer', 'payer', 'receiver',
        'payment_method', 'billing_address', 'shipping_address',
    ];
}
