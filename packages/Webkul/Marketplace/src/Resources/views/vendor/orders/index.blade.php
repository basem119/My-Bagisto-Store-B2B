<x-shop::layouts.account>
    <x-slot:title>
        @lang('marketplace::app.vendor.orders.title')
    </x-slot>

    <div class="mx-4 flex-auto max-md:mx-6 max-sm:mx-4">
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-2xl font-medium max-sm:text-base">@lang('marketplace::app.vendor.orders.title')</h2>
        </div>

        <table class="mb-8 w-full text-left">
            <thead>
                <tr>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.order-id')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.customer')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.status')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.your-items')</th>
                    <th class="border-b p-2">@lang('marketplace::app.vendor.orders.date')</th>
                    <th class="border-b p-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td class="border-b p-2">#{{ $order->increment_id }}</td>
                        <td class="border-b p-2">{{ $order->customer_first_name }} {{ $order->customer_last_name }}</td>
                        <td class="border-b p-2">{{ $order->status }}</td>
                        <td class="border-b p-2">{{ $order->vendor_item_count }}</td>
                        <td class="border-b p-2">{{ $order->created_at }}</td>
                        <td class="border-b p-2">
                            <a href="{{ route('marketplace.vendor.orders.show', [$vendor->slug, $order->id]) }}" class="text-blue-600">
                                @lang('marketplace::app.vendor.orders.view-btn')
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="border-b p-2" colspan="6">@lang('marketplace::app.vendor.orders.empty')</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $orders->appends(request()->query())->links() }}
    </div>
</x-shop::layouts.account>
