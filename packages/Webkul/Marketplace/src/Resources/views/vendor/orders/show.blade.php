<x-shop::layouts.account>
    <x-slot:title>
        @lang('marketplace::app.vendor.orders.detail-title') #{{ $order->increment_id }}
    </x-slot>

    <div class="mx-4 flex-auto max-md:mx-6 max-sm:mx-4">
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-2xl font-medium max-sm:text-base">
                @lang('marketplace::app.vendor.orders.detail-title') #{{ $order->increment_id }}
            </h2>

            <a href="{{ route('marketplace.vendor.orders.index', $vendor->slug) }}" class="text-blue-600">
                @lang('marketplace::app.vendor.orders.back-btn')
            </a>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-6 max-sm:grid-cols-1">
            <div>
                <h3 class="mb-2 font-medium">@lang('marketplace::app.vendor.orders.order-status')</h3>
                <p>{{ $order->status }}</p>
                <p>{{ $order->created_at }}</p>
            </div>

            @if ($order->shipping_address)
                <div>
                    <h3 class="mb-2 font-medium">@lang('marketplace::app.vendor.orders.shipping-address')</h3>
                    <p>{{ $order->shipping_address->first_name }} {{ $order->shipping_address->last_name }}</p>
                    <p>{{ $order->shipping_address->address1 }}</p>
                    @if ($order->shipping_address->address2)
                        <p>{{ $order->shipping_address->address2 }}</p>
                    @endif
                    <p>
                        {{ $order->shipping_address->city }},
                        {{ $order->shipping_address->state }}
                        {{ $order->shipping_address->postcode }}
                    </p>
                    <p>{{ $order->shipping_address->country }}</p>
                    @if ($order->shipping_address->phone)
                        <p>{{ $order->shipping_address->phone }}</p>
                    @endif
                </div>
            @endif
        </div>

        <h3 class="mb-2 font-medium">@lang('marketplace::app.vendor.orders.your-items')</h3>

        <table class="mb-8 w-full text-left">
            <thead>
                <tr>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.item-name')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.item-sku')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.vendor-price')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.qty-ordered')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.qty-shipped')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.qty-to-ship')</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td class="border-b p-2">{{ $item->name }}</td>
                        <td class="border-b p-2">{{ $item->additional['marketplace']['vendor_sku'] ?? $item->sku }}</td>
                        <td class="border-b p-2">{{ core()->formatBasePrice($item->additional['marketplace']['vendor_price'] ?? $item->base_price) }}</td>
                        <td class="border-b p-2">{{ (int) $item->qty_ordered }}</td>
                        <td class="border-b p-2">{{ (int) $item->qty_shipped }}</td>
                        <td class="border-b p-2">{{ (int) $item->qty_to_ship }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mb-4 rounded bg-slate-50 p-4 text-slate-600">
            @lang('marketplace::app.vendor.orders.fulfillment-deferred')
        </div>
    </div>
</x-shop::layouts.account>
