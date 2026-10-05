<?php

return [
    'admin' => [
        'acl' => [
            'marketplace' => 'Marketplace',
            'vendors' => 'Vendors',
            'view' => 'View',
            'approve' => 'Approve',
            'reject' => 'Reject',
            'suspend' => 'Suspend',
            'reactivate' => 'Reactivate',
            'vendor-products' => 'Vendor Products',
        ],
        'vendors' => [
            'index' => [
                'title' => 'Vendors',
            ],
            'view' => [
                'title' => 'Vendor Details',
                'approve-btn' => 'Approve',
                'reject-btn' => 'Reject',
                'suspend-btn' => 'Suspend',
                'reactivate-btn' => 'Reactivate',
            ],
        ],
        'vendor-products' => [
            'index' => [
                'title' => 'Vendor Products',
            ],
        ],
    ],
    'vendor' => [
        'onboarding' => [
            'title' => 'Become a Vendor',
            'submit-btn' => 'Submit Application',
        ],
        'dashboard' => [
            'title' => 'Vendor Dashboard',
        ],
        'profile' => [
            'title' => 'Vendor Profile',
            'save-btn' => 'Save Changes',
        ],
        'team' => [
            'title' => 'Team',
            'add-btn' => 'Add Member',
            'remove-btn' => 'Remove',
        ],
        'products' => [
            'title' => 'Products',
            'add-btn' => 'Add Product',
            'edit-btn' => 'Edit',
            'save-btn' => 'Save Offer',
            'remove-btn' => 'Remove',
        ],
    ],
    'shop' => [
        'index' => [
            'title' => 'Marketplace',
            'from' => 'From',
            'vendor-count' => '{1} :count vendor|[2,*] :count vendors',
            'empty' => 'No marketplace products found.',
        ],
        'show' => [
            'categories' => 'Categories',
            'offers-title' => 'Available Sellers',
            'no-offers' => 'No vendor offers are currently available for this product.',
            'vendor' => 'Vendor',
            'vendor-sku' => 'Vendor SKU',
            'price' => 'Price',
            'quantity' => 'Available Quantity',
        ],
    ],
];
