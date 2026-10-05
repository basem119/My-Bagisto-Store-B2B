<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\Models\Vendor;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;

/**
 * Read-only vendor-scoped view over the existing, canonical Bagisto
 * `orders`/`order_items` tables — NOT a second order system. Ownership of an
 * `order_item` is determined solely by the historical
 * `additional.marketplace.vendor_id` snapshot (Phase 10), never by the
 * item's `product_id` (one product can have many vendors) and never by a
 * live `VendorProduct` lookup (it may since have changed or been deleted —
 * see docs/architecture/marketplace-domain.md).
 */
class VendorOrderService
{
    /**
     * One row per Bagisto Order that contains at least one of this vendor's
     * order items, with a count of *this vendor's* items in that order (not
     * the order's total item count, which may include other vendors').
     * Database-filtered via the JSON path — never loads all orders and
     * filters in PHP.
     */
    public function paginateVendorOrders(Vendor $vendor, int $perPage = 20): LengthAwarePaginator
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.additional->marketplace->vendor_id', $vendor->id)
            ->groupBy(
                'orders.id',
                'orders.increment_id',
                'orders.customer_first_name',
                'orders.customer_last_name',
                'orders.status',
                'orders.created_at'
            )
            ->select([
                'orders.id',
                'orders.increment_id',
                'orders.customer_first_name',
                'orders.customer_last_name',
                'orders.status',
                'orders.created_at',
                DB::raw('COUNT(order_items.id) as vendor_item_count'),
            ])
            ->orderByDesc('orders.created_at')
            ->paginate($perPage);
    }

    /**
     * This vendor's own items within a specific order — any other vendor's
     * (or non-marketplace) items on the same order are never returned.
     */
    public function getVendorOrderItems(Vendor $vendor, Order $order): Collection
    {
        return $order->items()
            ->where('additional->marketplace->vendor_id', $vendor->id)
            ->get();
    }

    /**
     * Whether this vendor has any items at all on this order — the
     * authorization gate for the vendor order-detail page. An order a
     * vendor has zero items in must 404, not just render empty.
     */
    public function vendorHasItemsInOrder(Vendor $vendor, Order $order): bool
    {
        return $order->items()
            ->where('additional->marketplace->vendor_id', $vendor->id)
            ->exists();
    }

    /**
     * Authoritative ownership check for a single order item — used to
     * reject any attempt to act on an item that doesn't belong to the
     * authenticated vendor (defense in depth alongside route scoping).
     */
    public function vendorOwnsOrderItem(Vendor $vendor, OrderItem $orderItem): bool
    {
        return (int) ($orderItem->additional['marketplace']['vendor_id'] ?? 0) === $vendor->id;
    }
}
