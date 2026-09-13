<?php

namespace Edge;

use Edge\Operations\CreateOperation;
use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;
use Edge\Operations\UpdateOperation;

/** Physical address with locally resolved customer and merchant relationships. */
class ConsumerAddress extends ApiResource
{
    use ListOperation;
    use ShowOperation;
    use CreateOperation;
    use UpdateOperation;

    const TYPE = 'consumer_addresses';
    const SCHEMA = ['dates' => ['created_at', 'discarded_at', 'updated_at']];
    const FIELDS = [
        'line_1', 'line_2', 'city', 'state', 'zip', 'country',
        'created_at', 'discarded_at', 'updated_at',
        'merchant', 'customer',
    ];
}
