<x-shop::layouts>
    <x-slot:title>
        @lang('marketplace::app.shop.index.title')
    </x-slot>

    <div class="container mx-auto px-4 py-8">
        <h1 class="mb-6 text-2xl font-medium">@lang('marketplace::app.shop.index.title')</h1>

        <form method="GET" class="mb-6 flex gap-4">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Search products" class="rounded border p-2">
            <button type="submit" class="secondary-button">Search</button>
        </form>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            @forelse ($products as $product)
                <a href="{{ route('marketplace.shop.products.show', $product->url_key) }}" class="block rounded border p-4 hover:shadow">
                    <p class="font-medium">{{ $product->name }}</p>
                    <p class="text-gray-600">@lang('marketplace::app.shop.index.from') {{ core()->formatPrice($product->from_price) }}</p>
                    <p class="text-sm text-gray-500">
                        {{ trans_choice('marketplace::app.shop.index.vendor-count', $product->vendor_count, ['count' => $product->vendor_count]) }}
                    </p>
                </a>
            @empty
                <p>@lang('marketplace::app.shop.index.empty')</p>
            @endforelse
        </div>

        <div class="mt-8">
            {{ $products->appends(request()->query())->links() }}
        </div>
    </div>
</x-shop::layouts>
