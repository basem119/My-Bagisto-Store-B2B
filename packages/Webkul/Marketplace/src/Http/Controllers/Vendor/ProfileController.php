<?php

namespace Webkul\Marketplace\Http\Controllers\Vendor;

use Illuminate\View\View;
use Webkul\Marketplace\Http\Requests\VendorProfileUpdateRequest;
use Webkul\Marketplace\Models\Vendor;

/**
 * Vendor profile management. Only `name`/`email`/`phone`/`description` are
 * writable here — lifecycle `status` is never touched by this controller
 * (see VendorService, the only authority for status changes).
 */
class ProfileController extends Controller
{
    public function edit(Vendor $vendor): View
    {
        $this->authorize('view', $vendor);

        return view('marketplace::vendor.profile.edit', ['vendor' => $vendor]);
    }

    public function update(VendorProfileUpdateRequest $request, Vendor $vendor)
    {
        $this->authorize('update', $vendor);

        $vendor->update($request->validated());

        return redirect()
            ->route('marketplace.vendor.profile.edit', $vendor->slug)
            ->with('success', 'Vendor profile updated.');
    }
}
