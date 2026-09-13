<?php

namespace Edge;

use Edge\Operations\CreateOperation;
use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;

/** Refund details; eligibility, remaining balance, and processing belong to Edge. */
class RefundDemand extends ApiResource
{
    use ListOperation;
    use ShowOperation;
    use CreateOperation;

    const TYPE = 'refund_demands';
    const SCHEMA = [
        'dates' => ['created_at', 'updated_at'],
        'money' => ['amount' => ['amount_cents', 'amount_currency']],
    ];
    const FIELDS = [
        'state', 'reason', 'reason_note', 'amount_cents', 'amount_currency',
        'idempotency_key', 'created_at', 'updated_at', 'merchant', 'payment_demand',
    ];
}
