<?php

return [
    [
        'key' => 'marketplace',
        'name' => 'marketplace::app.admin.system.marketplace.title',
        'sort' => 90,
    ], [
        'key' => 'marketplace.financial',
        'name' => 'marketplace::app.admin.system.marketplace.financial.title',
        'info' => 'marketplace::app.admin.system.marketplace.financial.info',
        'icon' => 'settings/store.svg',
        'sort' => 1,
        'fields' => [
            [
                'name' => 'company_fee_rate',
                'title' => 'marketplace::app.admin.system.marketplace.financial.company-fee-rate',
                'type' => 'text',
                'default' => '0',
                'channel_based' => false,
                'locale_based' => false,
                'validation' => 'required|numeric|min:0|max:100',
            ], [
                'name' => 'default_vendor_commission_rate',
                'title' => 'marketplace::app.admin.system.marketplace.financial.default-vendor-commission-rate',
                'type' => 'text',
                'default' => '0',
                'channel_based' => false,
                'locale_based' => false,
                'validation' => 'required|numeric|min:0|max:100',
            ],
        ],
    ],
];