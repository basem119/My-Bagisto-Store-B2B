<x-admin::layouts>
    <x-slot:title>
        @lang('marketplace::app.admin.vendors.view.title')
    </x-slot>

    <p class="mb-4 text-xl font-bold text-gray-800 dark:text-white">{{ $vendor->name }}</p>

    <dl class="mb-6 grid grid-cols-2 gap-4">
        <dt class="font-medium">Status</dt>
        <dd>{{ $vendor->status->label() }}</dd>

        <dt class="font-medium">Email</dt>
        <dd>{{ $vendor->email }}</dd>

        <dt class="font-medium">Phone</dt>
        <dd>{{ $vendor->phone }}</dd>
    </dl>

    <div class="mb-8 flex gap-4">
        @if ($vendor->status->canTransitionTo(\Webkul\Marketplace\Enums\VendorStatus::ACTIVE) && $vendor->status->value === 'pending')
            <form method="POST" action="{{ route('marketplace.admin.vendors.approve', $vendor->id) }}">
                @csrf
                <button type="submit" class="primary-button">@lang('marketplace::app.admin.vendors.view.approve-btn')</button>
            </form>
        @endif

        @if ($vendor->status->value === 'pending')
            <form method="POST" action="{{ route('marketplace.admin.vendors.reject', $vendor->id) }}">
                @csrf
                <button type="submit" class="secondary-button">@lang('marketplace::app.admin.vendors.view.reject-btn')</button>
            </form>
        @endif

        @if ($vendor->status->value === 'active')
            <form method="POST" action="{{ route('marketplace.admin.vendors.suspend', $vendor->id) }}">
                @csrf
                <button type="submit" class="secondary-button">@lang('marketplace::app.admin.vendors.view.suspend-btn')</button>
            </form>
        @endif

        @if (in_array($vendor->status->value, ['suspended', 'inactive']))
            <form method="POST" action="{{ route('marketplace.admin.vendors.reactivate', $vendor->id) }}">
                @csrf
                <button type="submit" class="primary-button">@lang('marketplace::app.admin.vendors.view.reactivate-btn')</button>
            </form>
        @endif
    </div>

    <p class="mb-2 text-lg font-medium">Team Members</p>
    <table class="mb-8 w-full text-left">
        <thead>
            <tr>
                <th class="border-b p-2">Customer</th>
                <th class="border-b p-2">Role</th>
                <th class="border-b p-2">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($members as $member)
                <tr>
                    <td class="border-b p-2">{{ $member->customer?->name }}</td>
                    <td class="border-b p-2">{{ $member->role->label() }}</td>
                    <td class="border-b p-2">{{ $member->status->value }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="mb-2 text-lg font-medium">Status History</p>
    <table class="w-full text-left">
        <thead>
            <tr>
                <th class="border-b p-2">From</th>
                <th class="border-b p-2">To</th>
                <th class="border-b p-2">Note</th>
                <th class="border-b p-2">When</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statusHistories as $history)
                <tr>
                    <td class="border-b p-2">{{ $history->from_status ?? '—' }}</td>
                    <td class="border-b p-2">{{ $history->to_status }}</td>
                    <td class="border-b p-2">{{ $history->note }}</td>
                    <td class="border-b p-2">{{ $history->created_at }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-admin::layouts>
