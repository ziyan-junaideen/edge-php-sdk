<?php

namespace Edge\Tests;

use Edge\ApiResource;
use Edge\Operations\ConfirmOperation;
use Edge\Operations\CreateOperation;
use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;
use Edge\Operations\UpdateOperation;

/** Synthetic endpoint used only with mocked HTTP. */
class OperationResource extends ApiResource
{
    use ListOperation;
    use ShowOperation;
    use CreateOperation;
    use UpdateOperation;
    use ConfirmOperation;

    const TYPE = 'test_records';
    const SCHEMA = [
        'dates' => ['created_at'],
        'money' => ['amount' => ['amount_cents', 'amount_currency']],
    ];
    const FIELDS = ['name', 'buyer'];
}
