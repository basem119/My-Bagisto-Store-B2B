<?php

return [
    'admin' => [
        'acl' => [
            'commission' => 'إدارة عمولة البائع',
        ],
        'system' => [
            'marketplace' => [
                'title' => 'السوق الإلكتروني',
                'financial' => [
                    'title' => 'الإعدادات المالية',
                    'info' => 'إعداد رسوم الشركة والعمولة الافتراضية للبائع.',
                    'company-fee-rate' => 'نسبة رسوم منصة الشركة (%)',
                    'default-vendor-commission-rate' => 'نسبة عمولة البائع الافتراضية (%)',
                ],
            ],
        ],
        'vendors' => [
            'view' => [
                'commission-rate' => 'نسبة العمولة (%)',
                'use-default-rate' => 'استخدم النسبة الافتراضية',
                'save-commission-rate' => 'حفظ النسبة',
                'commission-rate-updated' => 'تم تحديث نسبة عمولة البائع.',
            ],
        ],
        'invoices' => [
            'company-fee' => 'رسوم منصة الشركة',
        ],
    ],
    'shop' => [
        'checkout' => [
            'company-fee' => 'رسوم منصة الشركة',
        ],
    ],
];