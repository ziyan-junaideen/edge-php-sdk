<?php

namespace Edge\Tests;

use Edge\ApiResource;
use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;

class ReadOnlyOperationResource extends ApiResource
{
    use ListOperation;
    use ShowOperation;

    const TYPE = 'test_read_only';
}
