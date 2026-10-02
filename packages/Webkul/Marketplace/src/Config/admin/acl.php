<?php

return [
    [
        'key' => 'marketplace',
        'name' => 'marketplace::app.admin.acl.marketplace',
        'route' => 'marketplace.admin.vendors.index',
        'sort' => 1,
    ], [
        'key' => 'marketplace.vendors',
        'name' => 'marketplace::app.admin.acl.vendors',
        'route' => 'marketplace.admin.vendors.index',
        'sort' => 1,
    ], [
        'key' => 'marketplace.vendors.view',
        'name' => 'marketplace::app.admin.acl.view',
        'route' => 'marketplace.admin.vendors.view',
        'sort' => 1,
    ], [
        'key' => 'marketplace.vendors.approve',
        'name' => 'marketplace::app.admin.acl.approve',
        'route' => 'marketplace.admin.vendors.approve',
        'sort' => 2,
    ], [
        'key' => 'marketplace.vendors.reject',
        'name' => 'marketplace::app.admin.acl.reject',
        'route' => 'marketplace.admin.vendors.reject',
        'sort' => 3,
    ], [
        'key' => 'marketplace.vendors.suspend',
        'name' => 'marketplace::app.admin.acl.suspend',
        'route' => 'marketplace.admin.vendors.suspend',
        'sort' => 4,
    ], [
        'key' => 'marketplace.vendors.reactivate',
        'name' => 'marketplace::app.admin.acl.reactivate',
        'route' => 'marketplace.admin.vendors.reactivate',
        'sort' => 5,
    ],
];
