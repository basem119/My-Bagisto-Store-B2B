<x-shop::layouts.account>
    <x-slot:title>
        @lang('marketplace::app.vendor.team.title')
    </x-slot>

    <div class="mx-4 flex-auto max-md:mx-6 max-sm:mx-4">
        <h2 class="mb-6 text-2xl font-medium max-sm:text-base">@lang('marketplace::app.vendor.team.title')</h2>

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
                    <th class="border-b p-2">Name</th>
                    <th class="border-b p-2">Email</th>
                    <th class="border-b p-2">Role</th>
                    <th class="border-b p-2">Status</th>
                    <th class="border-b p-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($members as $member)
                    <tr>
                        <td class="border-b p-2">{{ $member->customer?->name }}</td>
                        <td class="border-b p-2">{{ $member->customer?->email }}</td>
                        <td class="border-b p-2">{{ $member->role->label() }}</td>
                        <td class="border-b p-2">{{ $member->status->value }}</td>
                        <td class="border-b p-2">
                            @if ($member->role->value !== 'owner')
                                <form method="POST" action="{{ route('marketplace.vendor.team.destroy', [$vendor->slug, $member->customer_id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600">@lang('marketplace::app.vendor.team.remove-btn')</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h3 class="mb-4 text-lg font-medium">@lang('marketplace::app.vendor.team.add-btn')</h3>

        <form method="POST" action="{{ route('marketplace.vendor.team.store', $vendor->slug) }}" class="flex items-end gap-4">
            @csrf

            <div>
                <label class="mb-1 block font-medium">Customer Email</label>
                <input type="email" name="email" class="rounded border p-2" required>
            </div>

            <div>
                <label class="mb-1 block font-medium">Role</label>
                <select name="role" class="rounded border p-2">
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="staff" selected>Staff</option>
                </select>
            </div>

            <button type="submit" class="primary-button">
                @lang('marketplace::app.vendor.team.add-btn')
            </button>
        </form>
    </div>
</x-shop::layouts.account>
