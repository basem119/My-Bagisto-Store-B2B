<x-admin::layouts>
    <x-slot:title>
        @lang('marketplace::app.admin.vendor-products.index.title')
    </x-slot>

    <p class="mb-4 text-xl font-bold text-gray-800 dark:text-white">
        @lang('marketplace::app.admin.vendor-products.index.title')
    </p>

    <table class="mb-8 w-full text-left">
        <thead>
            <tr>
                <th class="border-b p-2">Vendor</th>
                <th class="border-b p-2">Product</th>
                <th class="border-b p-2">Vendor SKU</th>
                <th class="border-b p-2">Price</th>
                <th class="border-b p-2">Quantity</th>
                <th class="border-b p-2">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($offers as $offer)
                <tr>
                    <td class="border-b p-2">{{ $offer->vendor?->name }}</td>
                    <td class="border-b p-2">{{ $offer->product?->sku }}</td>
                    <td class="border-b p-2">{{ $offer->vendor_sku }}</td>
                    <td class="border-b p-2">{{ $offer->price }}</td>
                    <td class="border-b p-2">{{ $offer->quantity }}</td>
                    <td class="border-b p-2">{{ $offer->status->value }}</td>
                </tr>
            @empty
                <tr>
                    <td class="border-b p-2" colspan="6">No vendor offers yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $offers->links() }}
</x-admin::layouts>
