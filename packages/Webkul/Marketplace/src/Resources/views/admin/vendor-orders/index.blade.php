<x-admin::layouts>
    <x-slot:title>
        @lang('marketplace::app.admin.vendor-orders.index.title')
    </x-slot>

    <p class="mb-4 text-xl font-bold text-gray-800 dark:text-white">
        @lang('marketplace::app.admin.vendor-orders.index.title')
    </p>

    <table class="mb-8 w-full text-left">
        <thead>
            <tr>
                <th class="border-b p-2">Order #</th>
                <th class="border-b p-2">Item</th>
                <th class="border-b p-2">Vendor</th>
                <th class="border-b p-2">Vendor SKU</th>
                <th class="border-b p-2">Vendor Price</th>
                <th class="border-b p-2">Qty Ordered</th>
                <th class="border-b p-2">Date</th>
                <th class="border-b p-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td class="border-b p-2">{{ $item->order?->increment_id }}</td>
                    <td class="border-b p-2">{{ $item->name }}</td>
                    <td class="border-b p-2">{{ $item->additional['marketplace']['vendor_name'] ?? '—' }}</td>
                    <td class="border-b p-2">{{ $item->additional['marketplace']['vendor_sku'] ?? '—' }}</td>
                    <td class="border-b p-2">{{ core()->formatBasePrice($item->additional['marketplace']['vendor_price'] ?? 0) }}</td>
                    <td class="border-b p-2">{{ (int) $item->qty_ordered }}</td>
                    <td class="border-b p-2">{{ $item->created_at }}</td>
                    <td class="border-b p-2">
                        @if ($item->order)
                            <a href="{{ route('admin.sales.orders.view', $item->order->id) }}" class="text-blue-600">
                                @lang('marketplace::app.admin.acl.view')
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="border-b p-2" colspan="8">@lang('marketplace::app.admin.vendor-orders.index.empty')</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $items->links() }}
</x-admin::layouts>
