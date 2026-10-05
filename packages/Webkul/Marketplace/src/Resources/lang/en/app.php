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
            'vendor-orders' => 'Vendor Orders',
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
        'vendor-orders' => [
            'index' => [
                'title' => 'Vendor Orders',
                'empty' => 'No marketplace order items yet.',
            ],
        ],
        'order-item' => [
            'title' => 'Marketplace Offer',
            'vendor' => 'Vendor',
            'vendor-sku' => 'Vendor SKU',
            'vendor-offer' => 'Vendor Offer',
            'purchase-price' => 'Purchase Vendor Price',
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
        'orders' => [
            'title' => 'Orders',
            'order-id' => 'Order #',
            'customer' => 'Customer',
            'status' => 'Status',
            'your-items' => 'Your Items',
            'date' => 'Date',
            'view-btn' => 'View',
            'empty' => 'You have no orders yet.',
            'detail-title' => 'Order',
            'back-btn' => 'Back to Orders',
            'order-status' => 'Order Status',
            'shipping-address' => 'Shipping Address',
            'item-name' => 'Item',
            'item-sku' => 'SKU',
            'vendor-price' => 'Your Price',
            'qty-ordered' => 'Ordered',
            'qty-shipped' => 'Shipped',
            'qty-to-ship' => 'Remaining',
            'fulfillment-deferred' => 'Shipment creation from the vendor portal is not yet available. Contact the store admin to arrange fulfillment.',
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
            'add-to-cart-btn' => 'Add to Cart',
        ],
    ],
];
