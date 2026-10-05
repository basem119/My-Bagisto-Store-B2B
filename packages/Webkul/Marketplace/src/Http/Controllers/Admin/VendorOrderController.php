<?php

namespace Webkul\Marketplace\Http\Controllers\Admin;

use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Sales\Models\OrderItem;

/**
 * Oversight-only: inspect, across all vendors, which order items are
 * marketplace items and which vendor they belong to. No mutation — this is
 * a read-only cross-vendor view, since no single VendorPolicy membership
 * applies to the admin; admin visibility is already global via the
 * existing order list/detail and Phase 10's per-item marketplace panel.
 * This page exists only to make vendor ownership scannable without opening
 * every order individually.
 */
class VendorOrderController extends Controller
{
    public function index(): View
    {
        return view('marketplace::admin.vendor-orders.index', [
            'items' => OrderItem::query()
                ->whereNotNull('additional->marketplace->vendor_id')
                ->with('order')
                ->latest()
                ->paginate(20),
        ]);
    }
}
