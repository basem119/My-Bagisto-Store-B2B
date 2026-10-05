<x-shop::layouts>
    <x-slot:title>
        {{ $product->name }}
    </x-slot>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8 grid grid-cols-1 gap-8 md:grid-cols-2">
            <div>
                @foreach ($product->images as $image)
                    <img src="{{ $image->url }}" alt="{{ $product->name }}" class="mb-2 w-full rounded">
                @endforeach
            </div>

            <div>
                <h1 class="mb-2 text-2xl font-medium">{{ $product->name }}</h1>

                <div class="mb-4 text-gray-700">{!! $product->description !!}</div>

                @if ($product->categories->isNotEmpty())
                    <p class="mb-4 text-sm text-gray-500">
                        @lang('marketplace::app.shop.show.categories'): {{ $product->categories->pluck('name')->implode(', ') }}
                    </p>
                @endif
            </div>
        </div>

        <h2 class="mb-4 text-xl font-medium">@lang('marketplace::app.shop.show.offers-title')</h2>

        @if (session('success'))
            <div class="mb-4 rounded bg-green-50 p-4 text-green-700">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="mb-4 rounded bg-red-50 p-4 text-red-700">{{ session('error') }}</div>
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

        @if ($offers->isEmpty())
            <p>@lang('marketplace::app.shop.show.no-offers')</p>
        @else
            <table class="w-full text-left">
                <thead>
                    <tr>
                        <th class="border-b p-2">@lang('marketplace::app.shop.show.vendor')</th>
                        <th class="border-b p-2">@lang('marketplace::app.shop.show.vendor-sku')</th>
                        <th class="border-b p-2">@lang('marketplace::app.shop.show.price')</th>
                        <th class="border-b p-2">@lang('marketplace::app.shop.show.quantity')</th>
                        <th class="border-b p-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($offers as $offer)
                        <tr>
                            <td class="border-b p-2">{{ $offer->vendor->name }}</td>
                            <td class="border-b p-2">{{ $offer->vendor_sku }}</td>
                            <td class="border-b p-2">{{ core()->formatPrice($offer->price) }}</td>
                            <td class="border-b p-2">{{ $offer->quantity }}</td>
                            <td class="border-b p-2">
                                <form method="POST" action="{{ route('marketplace.shop.cart.add') }}" class="flex items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="vendor_product_id" value="{{ $offer->id }}">
                                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                                    <input type="number" name="quantity" value="1" min="1" max="{{ $offer->quantity }}" class="w-16 rounded border p-1">
                                    <button type="submit" class="primary-button">@lang('marketplace::app.shop.show.add-to-cart-btn')</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-shop::layouts>

