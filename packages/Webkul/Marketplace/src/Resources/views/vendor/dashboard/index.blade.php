<x-shop::layouts.account>
    <x-slot:title>
        @lang('marketplace::app.vendor.dashboard.title')
    </x-slot>

    <div class="mx-4 flex-auto max-md:mx-6 max-sm:mx-4">
        <h2 class="mb-6 text-2xl font-medium max-sm:text-base">{{ $vendor->name }}</h2>

        <dl class="grid grid-cols-2 gap-4">
            <dt class="font-medium">Status</dt>
            <dd>{{ $vendor->status->label() }}</dd>

            <dt class="font-medium">Your Role</dt>
            <dd>{{ $currentRole }}</dd>

            <dt class="font-medium">Team Members</dt>
            <dd>{{ $memberCount }}</dd>

            <dt class="font-medium">Products Listed</dt>
            <dd>{{ $productCount }}</dd>
        </dl>

        <div class="mt-6 flex gap-4">
            <a href="{{ route('marketplace.vendor.profile.edit', $vendor->slug) }}" class="primary-button">Profile</a>
            <a href="{{ route('marketplace.vendor.team.index', $vendor->slug) }}" class="primary-button">Team</a>
        </div>
    </div>
</x-shop::layouts.account>
