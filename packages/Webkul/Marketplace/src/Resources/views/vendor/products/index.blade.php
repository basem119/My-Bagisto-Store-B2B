<x-shop::layouts.account>
    <x-slot:title>
        @lang('marketplace::app.vendor.products.title')
    </x-slot>

    <div class="mx-4 flex-auto max-md:mx-6 max-sm:mx-4">
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-2xl font-medium max-sm:text-base">@lang('marketplace::app.vendor.products.title')</h2>

            <a href="{{ route('marketplace.vendor.products.create', $vendor->slug) }}" class="primary-button">
                @lang('marketplace::app.vendor.products.add-btn')
            </a>
        </div>

        @if (session('success'))
            <div class="mb-4 rounded bg-green-50 p-4 text-green-700">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded bg-red-50 p-4 text-red-700">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <table class="mb-8 w-full text-left">
            <thead>
                <tr>
                    <th class="border-b p-2">Product</th>
                    <th class="border-b p-2">Product SKU</th>
                    <th class="border-b p-2">Vendor SKU</th>
                    <th class="border-b p-2">Price</th>
                    <th class="border-b p-2">Quantity</th>
                    <th class="border-b p-2">Status</th>
                    <th class="border-b p-2">Updated</th>
                    <th class="border-b p-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($offers as $offer)
                    <tr>
                        <td class="border-b p-2">{{ $offer->product?->name ?? '—' }}</td>
                        <td class="border-b p-2">{{ $offer->product?->sku }}</td>
                        <td class="border-b p-2">{{ $offer->vendor_sku }}</td>
                        <td class="border-b p-2">{{ $offer->price }}</td>
                        <td class="border-b p-2">{{ $offer->quantity }}</td>
                        <td class="border-b p-2">{{ $offer->status->value }}</td>
                        <td class="border-b p-2">{{ $offer->updated_at }}</td>
                        <td class="border-b p-2 flex gap-2">
                            <a href="{{ route('marketplace.vendor.products.edit', [$vendor->slug, $offer->id]) }}" class="text-blue-600">
                                @lang('marketplace::app.vendor.products.edit-btn')
                            </a>
                            <form method="POST" action="{{ route('marketplace.vendor.products.destroy', [$vendor->slug, $offer->id]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600">@lang('marketplace::app.vendor.products.remove-btn')</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="border-b p-2" colspan="8">No offers yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $offers->links() }}
    </div>
</x-shop::layouts.account>
