<?php

namespace Edge;

use Edge\Operations\CreateOperation;
use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;
use Edge\Operations\UpdateOperation;

/** Customer name/contact details and locally resolved relationships. */
class Customer extends ApiResource
{
    use ListOperation;
    use ShowOperation;
    use CreateOperation;
    use UpdateOperation;

    const TYPE = 'customers';
    const SCHEMA = ['dates' => ['created_at', 'blocked_at', 'updated_at']];
    const FIELDS = [
        'name', 'phone_number', 'email', 'description',
        'created_at', 'blocked_at', 'updated_at',
        'merchant', 'addresses', 'payment_demands',
    ];
}
