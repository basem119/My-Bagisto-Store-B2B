<x-shop::layouts.account>
    <x-slot:title>
        @lang('marketplace::app.vendor.products.edit-btn')
    </x-slot>

    <div class="mx-4 flex-auto max-md:mx-6 max-sm:mx-4">
        <h2 class="mb-6 text-2xl font-medium max-sm:text-base">{{ $offer->product?->name }}</h2>

        @if ($errors->any())
            <div class="mb-4 rounded bg-red-50 p-4 text-red-700">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('marketplace.vendor.products.update', [$vendor->slug, $offer->id]) }}" class="max-w-md space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label class="mb-1 block font-medium">Vendor SKU</label>
                <input type="text" name="vendor_sku" value="{{ old('vendor_sku', $offer->vendor_sku) }}" class="w-full rounded border p-2">
            </div>

            <div>
                <label class="mb-1 block font-medium">Price</label>
                <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $offer->price) }}" class="w-full rounded border p-2" required>
            </div>

            <div>
                <label class="mb-1 block font-medium">Quantity</label>
                <input type="number" min="0" name="quantity" value="{{ old('quantity', $offer->quantity) }}" class="w-full rounded border p-2" required>
            </div>

            <div>
                <label class="mb-1 block font-medium">Status</label>
                <select name="status" class="w-full rounded border p-2">
                    @foreach (['draft', 'active', 'inactive'] as $status)
                        <option value="{{ $status }}" @selected(old('status', $offer->status->value) === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="primary-button">@lang('marketplace::app.vendor.products.save-btn')</button>
        </form>
    </div>
</x-shop::layouts.account>
