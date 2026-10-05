<?php

namespace Webkul\Marketplace\Http\Controllers\Vendor;

use Illuminate\View\View;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Marketplace\Services\VendorOrderService;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\OrderRepository;

/**
 * Vendor-scoped order visibility — a filtered view of the canonical Bagisto
 * Order, never a second order system. `view` (any active member — see
 * VendorPolicy) gates both listing and detail, matching the read-only
 * nature of this controller; no write actions exist here (shipment/
 * fulfillment actions are deferred, see
 * docs/architecture/marketplace-domain.md).
 *
 * `int $orderId` + repository `findOrFail()` (not route-model binding) to
 * match core Bagisto's own Admin\Sales\OrderController convention.
 */
class OrderController extends Controller
{
    public function __construct(
        protected VendorOrderService $vendorOrderService,
        protected OrderRepository $orderRepository,
    ) {}

    public function index(Vendor $vendor): View
    {
        $this->authorize('view', $vendor);

        return view('marketplace::vendor.orders.index', [
            'vendor' => $vendor,
            'orders' => $this->vendorOrderService->paginateVendorOrders($vendor),
        ]);
    }

    public function show(Vendor $vendor, int $orderId): View
    {
        $this->authorize('view', $vendor);

        $order = $this->orderRepository->findOrFail($orderId);

        $this->assertVendorHasItemsInOrder($vendor, $order);

        return view('marketplace::vendor.orders.show', [
            'vendor' => $vendor,
            'order' => $order,
            'items' => $this->vendorOrderService->getVendorOrderItems($vendor, $order),
        ]);
    }

    /**
     * `EnsureVendorContext` only proves membership in the URL's {vendor} —
     * it proves nothing about whether this specific order contains any of
     * that vendor's items. An order with zero items for this vendor (either
     * entirely another vendor's/non-marketplace order, or just not this
     * vendor's) must 404, not render an empty/misleading page.
     */
    protected function assertVendorHasItemsInOrder(Vendor $vendor, Order $order): void
    {
        abort_unless($this->vendorOrderService->vendorHasItemsInOrder($vendor, $order), 404);
    }
}
