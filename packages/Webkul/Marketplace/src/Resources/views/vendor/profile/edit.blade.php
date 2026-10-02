<x-shop::layouts.account>
    <x-slot:title>
        @lang('marketplace::app.vendor.profile.title')
    </x-slot>

    <div class="mx-4 flex-auto max-md:mx-6 max-sm:mx-4">
        <h2 class="mb-6 text-2xl font-medium max-sm:text-base">@lang('marketplace::app.vendor.profile.title')</h2>

        @if ($errors->any())
            <div class="mb-4 rounded bg-red-50 p-4 text-red-700">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('marketplace.vendor.profile.update', $vendor->slug) }}">
            @csrf
            @method('PUT')

            <div class="mb-4">
                <label class="mb-1 block font-medium">Name</label>
                <input type="text" name="name" value="{{ old('name', $vendor->name) }}" class="w-full rounded border p-2" required>
            </div>

            <div class="mb-4">
                <label class="mb-1 block font-medium">Email</label>
                <input type="email" name="email" value="{{ old('email', $vendor->email) }}" class="w-full rounded border p-2" required>
            </div>

            <div class="mb-4">
                <label class="mb-1 block font-medium">Phone</label>
                <input type="text" name="phone" value="{{ old('phone', $vendor->phone) }}" class="w-full rounded border p-2">
            </div>

            <div class="mb-4">
                <label class="mb-1 block font-medium">Description</label>
                <textarea name="description" class="w-full rounded border p-2">{{ old('description', $vendor->description) }}</textarea>
            </div>

            <button type="submit" class="primary-button">
                @lang('marketplace::app.vendor.profile.save-btn')
            </button>
        </form>
    </div>
</x-shop::layouts.account>
