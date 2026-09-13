<?php

namespace Edge;

use Edge\Operations\ListOperation;
use Edge\Operations\ShowOperation;

/** Read-only merchant business profile and exposed relationships. */
class Merchant extends ApiResource
{
    use ListOperation;
    use ShowOperation;

    const TYPE = 'merchants';
    const SCHEMA = ['dates' => ['active_at', 'created_at', 'updated_at']];
    const FIELDS = [
        'business_name', 'business_description', 'phone_number', 'business_email',
        'prior_bankruptcies', 'category_code', 'business_privacy_policy_url',
        'business_support_email', 'business_support_url', 'business_terms_url',
        'business_timezone', 'business_website', 'business_zip_code', 'entity_type',
        'active_at', 'created_at', 'updated_at',
        'customers', 'payment_demands', 'payment_methods', 'beneficial_owners', 'corporate_officials',
    ];
}
