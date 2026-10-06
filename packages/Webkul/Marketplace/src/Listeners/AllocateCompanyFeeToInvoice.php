<?php

namespace Webkul\Marketplace\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\DataTypes\FinancialRate;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\MarketplaceFinancializationException;
use Webkul\Marketplace\Models\InvoiceFinancial;
use Webkul\Marketplace\Models\OrderFinancial;
use Webkul\Sales\Contracts\Invoice as InvoiceContract;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;

class AllocateCompanyFeeToInvoice
{
    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected OrderRepository $orderRepository,
    ) {}

    public function handle(InvoiceContract $invoice): void
    {
        if (! $invoice instanceof Invoice) {
            return;
        }

        $financial = OrderFinancial::query()->where('order_id', $invoice->order_id)->first();

        if (! $financial || EgpAmount::toMinorUnits((string) $financial->company_fee_amount) === 0) {
            return;
        }

        if ($invoice->order_currency_code !== FinancialCurrency::EGP->value) {
            throw new MarketplaceFinancializationException('Marketplace invoice fees support EGP invoices only.');
        }

        DB::transaction(function () use ($invoice, $financial) {
            $financial = OrderFinancial::query()->whereKey($financial->id)->lockForUpdate()->firstOrFail();
            $invoiceItems = DB::table('invoice_items')
                ->where('invoice_id', $invoice->id)
                ->whereNull('parent_id')
                ->whereNotNull('order_item_id')
                ->get(['base_total', 'base_discount_amount']);

            $invoiceProductBasis = $this->discountedProductBasis($invoiceItems);
            $allInvoicedBasis = DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.order_id', $invoice->order_id)
                ->whereNull('invoice_items.parent_id')
                ->whereNotNull('invoice_items.order_item_id')
                ->get(['invoice_items.base_total', 'invoice_items.base_discount_amount']);
            $cumulativeBasis = $this->discountedProductBasis($allInvoicedBasis);
            $orderBasis = EgpAmount::toPrecisionUnits((string) $financial->discounted_product_subtotal);

            if ($cumulativeBasis > $orderBasis || $invoiceProductBasis > $cumulativeBasis) {
                throw new MarketplaceFinancializationException('Invoiced discounted product value exceeds the financialized order basis.');
            }

            $targetCumulativeFee = min(
                EgpAmount::toMinorUnits((string) $financial->company_fee_amount),
                FinancialRate::percentOfPrecisionUnits($cumulativeBasis, (string) $financial->company_fee_rate)
            );

            $allocatedRows = InvoiceFinancial::query()
                ->where('order_financial_id', $financial->id)
                ->get(['invoice_id', 'company_fee_amount']);
            $allocatedSoFar = $allocatedRows
                ->sum(fn (InvoiceFinancial $row) => EgpAmount::toMinorUnits((string) $row->company_fee_amount));

            $existingAllocation = InvoiceFinancial::query()
                ->where('invoice_id', $invoice->id)
                ->first();

            $existingOnThisInvoice = $existingAllocation
                ? EgpAmount::toMinorUnits((string) $existingAllocation->company_fee_amount)
                : 0;
            $previouslyAllocated = $allocatedSoFar - $existingOnThisInvoice;
            $feeForInvoice = $targetCumulativeFee - $previouslyAllocated;

            if ($feeForInvoice < 0) {
                throw new MarketplaceFinancializationException('Existing invoice fee allocations exceed the cumulative company fee.');
            }

            if ($existingAllocation) {
                if ($existingOnThisInvoice !== $feeForInvoice) {
                    throw new MarketplaceFinancializationException('The existing invoice company fee allocation conflicts with the financial allocation.');
                }

                return;
            }

            if ($feeForInvoice === 0) {
                return;
            }

            $amount = EgpAmount::toDatabaseDecimal($feeForInvoice);
            InvoiceFinancial::create([
                'invoice_id' => $invoice->id,
                'order_financial_id' => $financial->id,
                'currency' => FinancialCurrency::EGP->value,
                'company_fee_amount' => $amount,
            ]);

            $baseSubtotal = EgpAmount::toDatabaseDecimal(
                EgpAmount::toMinorUnits((string) $invoice->base_sub_total) + $feeForInvoice
            );
            $subtotal = EgpAmount::toDatabaseDecimal(
                EgpAmount::toMinorUnits((string) $invoice->sub_total) + $feeForInvoice
            );
            $baseGrandTotal = EgpAmount::toDatabaseDecimal(
                EgpAmount::toMinorUnits((string) $invoice->base_grand_total) + $feeForInvoice
            );
            $grandTotal = EgpAmount::toDatabaseDecimal(
                EgpAmount::toMinorUnits((string) $invoice->grand_total) + $feeForInvoice
            );

            DB::table('invoices')->where('id', $invoice->id)->update([
                'base_sub_total' => $baseSubtotal,
                'sub_total' => $subtotal,
                'base_grand_total' => $baseGrandTotal,
                'grand_total' => $grandTotal,
                'updated_at' => now(),
            ]);

            $invoice->setAttribute('base_sub_total', $baseSubtotal);
            $invoice->setAttribute('sub_total', $subtotal);
            $invoice->setAttribute('base_grand_total', $baseGrandTotal);
            $invoice->setAttribute('grand_total', $grandTotal);

            $this->orderRepository->collectTotals($invoice->order()->firstOrFail());
        });
    }

    private function discountedProductBasis(iterable $items): int
    {
        $basis = 0;

        foreach ($items as $item) {
            $total = EgpAmount::toPrecisionUnits((string) $item->base_total);
            $discount = EgpAmount::toPrecisionUnits((string) ($item->base_discount_amount ?? 0));

            if ($discount > $total) {
                throw new MarketplaceFinancializationException('Invoice product discounts exceed the invoiced product total.');
            }

            $basis += $total - $discount;
        }

        return $basis;
    }
}