<?php

namespace Edge;

use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;

/** Read-only event record. The nested data payload remains opaque JSON data. */
class Event extends ApiResource
{
    use ListOperation;
    use ShowOperation;

    const TYPE = 'events';
    const SCHEMA = ['dates' => ['created_at']];
    const FIELDS = [
        'data', 'mode', 'resource_id', 'resource_type', 'slug', 'created_at', 'merchant',
    ];
}
