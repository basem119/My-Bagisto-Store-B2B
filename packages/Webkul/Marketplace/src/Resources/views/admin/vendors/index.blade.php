<x-admin::layouts>
    <x-slot:title>
        @lang('marketplace::app.admin.vendors.index.title')
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            @lang('marketplace::app.admin.vendors.index.title')
        </p>
    </div>

    <div class="mt-4 overflow-auto rounded box-shadow">
        <table class="w-full text-left">
            <thead>
                <tr>
                    <th class="border-b p-2">ID</th>
                    <th class="border-b p-2">Name</th>
                    <th class="border-b p-2">Email</th>
                    <th class="border-b p-2">Status</th>
                    <th class="border-b p-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($vendors as $vendor)
                    <tr>
                        <td class="border-b p-2">{{ $vendor->id }}</td>
                        <td class="border-b p-2">{{ $vendor->name }}</td>
                        <td class="border-b p-2">{{ $vendor->email }}</td>
                        <td class="border-b p-2">{{ $vendor->status->label() }}</td>
                        <td class="border-b p-2">
                            <a href="{{ route('marketplace.admin.vendors.view', $vendor->id) }}">
                                @lang('marketplace::app.admin.acl.view')
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $vendors->links() }}
    </div>
</x-admin::layouts>
