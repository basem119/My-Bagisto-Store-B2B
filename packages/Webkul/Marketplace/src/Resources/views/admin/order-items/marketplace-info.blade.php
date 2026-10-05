@if (isset($item->additional['marketplace']))
    @php($marketplace = $item->additional['marketplace'])

    <div class="mt-1.5 rounded border border-dashed border-gray-300 p-2 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-300">
        <p class="font-semibold">@lang('marketplace::app.admin.order-item.title')</p>
        <p>@lang('marketplace::app.admin.order-item.vendor'): {{ $marketplace['vendor_name'] ?? '—' }}</p>
        <p>@lang('marketplace::app.admin.order-item.vendor-sku'): {{ $marketplace['vendor_sku'] ?? '—' }}</p>
        <p>@lang('marketplace::app.admin.order-item.vendor-offer'): #{{ $marketplace['vendor_product_id'] ?? '—' }}</p>
        <p>@lang('marketplace::app.admin.order-item.purchase-price'): {{ core()->formatBasePrice($marketplace['vendor_price'] ?? 0) }}</p>
    </div>
@endif
