<x-shop::layouts>
    <x-slot:title>
        @lang('marketplace::app.vendor.onboarding.title')
    </x-slot>

    <div class="mx-auto max-w-xl px-4 py-8">
        <h1 class="mb-6 text-2xl font-medium">@lang('marketplace::app.vendor.onboarding.title')</h1>

        @if ($errors->any())
            <div class="mb-4 rounded bg-red-50 p-4 text-red-700">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('marketplace.vendor.apply.store') }}">
            @csrf

            <div class="mb-4">
                <label class="mb-1 block font-medium">Vendor / Business Name</label>
                <input type="text" name="name" value="{{ old('name') }}" class="w-full rounded border p-2" required>
            </div>

            <div class="mb-4">
                <label class="mb-1 block font-medium">Contact Email</label>
                <input type="email" name="email" value="{{ old('email') }}" class="w-full rounded border p-2" required>
            </div>

            <div class="mb-4">
                <label class="mb-1 block font-medium">Phone</label>
                <input type="text" name="phone" value="{{ old('phone') }}" class="w-full rounded border p-2">
            </div>

            <div class="mb-4">
                <label class="mb-1 block font-medium">Description</label>
                <textarea name="description" class="w-full rounded border p-2">{{ old('description') }}</textarea>
            </div>

            <button type="submit" class="primary-button">
                @lang('marketplace::app.vendor.onboarding.submit-btn')
            </button>
        </form>
    </div>
</x-shop::layouts>
