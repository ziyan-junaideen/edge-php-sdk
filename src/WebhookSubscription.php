<?php

namespace Edge;

use Edge\Operations\CreateOperation;
use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;
use Edge\Operations\UpdateOperation;

/** Webhook configuration and locally resolved merchant/delivery relationships. */
class WebhookSubscription extends ApiResource
{
    use ListOperation;
    use ShowOperation;
    use CreateOperation;
    use UpdateOperation;

    const TYPE = 'webhook_subscriptions';
    const SCHEMA = ['dates' => ['archived_at', 'created_at', 'updated_at']];
    const FIELDS = [
        'status', 'mode', 'concurrency_limit', 'description', 'events', 'secret_key', 'url',
        'archived_at', 'created_at', 'updated_at', 'merchant', 'webhook_deliveries',
    ];
}
