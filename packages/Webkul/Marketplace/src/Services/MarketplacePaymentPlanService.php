<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Enums\InstallmentStatus;
use Webkul\Marketplace\Enums\PaymentPlanStatus;
use Webkul\Marketplace\Exceptions\MarketplaceCreditException;
use Webkul\Marketplace\Models\CompanyPaymentPolicy;
use Webkul\Marketplace\Models\PaymentInstallment;
use Webkul\Marketplace\Models\PaymentPlan;
use Webkul\Sales\Contracts\Order as OrderContract;

/**
 * Creates, exactly once per order, a historical payment-plan snapshot for
 * credit-funded orders. The agreed term/installment count are frozen at
 * creation time and are immune to later changes to the company's payment
 * policy (the policy is only consulted to validate the request at creation
 * time, never re-read afterwards).
 */
class MarketplacePaymentPlanService
{
    public function __construct(
        protected MarketplaceCreditValidationService $creditValidationService,
    ) {}

    /**
     * Idempotent: calling this again for the same order returns the existing
     * plan (after verifying it matches the requested parameters) instead of
     * creating a duplicate.
     */
    public function createForOrder(
        OrderContract $order,
        Model $company,
        ?int $requestedTermDays = null,
        ?int $installmentCount = null,
    ): PaymentPlan {
        return DB::transaction(function () use ($order, $company, $requestedTermDays, $installmentCount) {
            $totalMinor = EgpAmount::toMinorUnits((string) $order->base_grand_total);

            if ($totalMinor <= 0) {
                throw new MarketplaceCreditException('A payment plan requires a positive order amount.');
            }

            $existing = PaymentPlan::query()->where('order_id', $order->id)->lockForUpdate()->first();

            if ($existing) {
                $this->assertExistingPlanMatches($existing, $company, $requestedTermDays, $installmentCount, $totalMinor);

                return $existing->load('installments');
            }

            $policy = CompanyPaymentPolicy::query()->where('company_id', $company->id)->first();

            if (! $policy || ! $policy->is_active) {
                throw new MarketplaceCreditException("Company #{$company->id} has no active payment policy.");
            }

            $termDays = $requestedTermDays ?? $policy->maximum_term_days;

            if ($termDays > $policy->maximum_term_days) {
                throw new MarketplaceCreditException(
                    "Requested payment term ({$termDays} days) exceeds company #{$company->id}'s approved maximum ({$policy->maximum_term_days} days)."
                );
            }

            $installments = $installmentCount ?? 1;

            if ($installments < 1) {
                throw new MarketplaceCreditException('A payment plan must have at least one installment.');
            }

            if ($installments > 1 && ! $policy->installments_allowed) {
                throw new MarketplaceCreditException("Company #{$company->id} is not permitted to use installments.");
            }

            if ($installments > $policy->maximum_installments) {
                throw new MarketplaceCreditException(
                    "Requested installment count ({$installments}) exceeds company #{$company->id}'s maximum ({$policy->maximum_installments})."
                );
            }

            $this->creditValidationService->assertProjectedExposureWithinLimit($company, $totalMinor, $order->id);

            $amounts = $this->splitAmount($totalMinor, $installments);

            $plan = new PaymentPlan;
            $plan->forceFill([
                'order_id'            => $order->id,
                'company_id'          => $company->id,
                'requested_term_days' => $termDays,
                'approved_term_days'  => $termDays,
                'due_date'            => now()->addDays($termDays)->toDateString(),
                'currency'            => FinancialCurrency::EGP->value,
                'total_amount'        => EgpAmount::toDatabaseDecimal($totalMinor),
                'status'              => PaymentPlanStatus::ACTIVE->value,
            ])->save();

            foreach ($amounts as $index => $amountMinor) {
                $installmentNumber = $index + 1;

                $installment = new PaymentInstallment;
                $installment->forceFill([
                    'payment_plan_id'    => $plan->id,
                    'installment_number' => $installmentNumber,
                    'currency'           => FinancialCurrency::EGP->value,
                    'amount'             => EgpAmount::toDatabaseDecimal($amountMinor),
                    'paid_amount'        => EgpAmount::toDatabaseDecimal(0),
                    'due_date'           => now()->addDays(intdiv($termDays * $installmentNumber, $installments))->toDateString(),
                    'status'             => InstallmentStatus::PENDING->value,
                ])->save();
            }

            return $plan->load('installments');
        });
    }

    /**
     * Marks pending/partially-paid installments whose due date has passed as
     * overdue. Intended to be invoked explicitly (e.g. from a scheduled
     * command); no scheduler is wired up as part of this phase.
     */
    public function markOverdueInstallments(): int
    {
        return PaymentInstallment::query()
            ->whereIn('status', [InstallmentStatus::PENDING->value, InstallmentStatus::PARTIALLY_PAID->value])
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => InstallmentStatus::OVERDUE->value]);
    }

    /**
     * @return array<int, int> installment amounts in minor units, index 0 = installment #1
     */
    private function splitAmount(int $totalMinor, int $count): array
    {
        $base = intdiv($totalMinor, $count);
        $remainder = $totalMinor % $count;

        if ($base === 0) {
            throw new MarketplaceCreditException('The order amount is too small to support the requested installment count.');
        }

        $amounts = array_fill(0, $count, $base);
        $amounts[$count - 1] += $remainder;

        return $amounts;
    }

    private function assertExistingPlanMatches(
        PaymentPlan $existing,
        Model $company,
        ?int $requestedTermDays,
        ?int $installmentCount,
        int $totalMinor,
    ): void {
        if (
            (int) $existing->company_id !== (int) $company->id
            || ($requestedTermDays !== null && (int) $existing->approved_term_days !== $requestedTermDays)
            || ($installmentCount !== null && $existing->installments()->count() !== $installmentCount)
            || EgpAmount::toMinorUnits((string) $existing->total_amount) !== $totalMinor
        ) {
            throw new MarketplaceCreditException("Existing payment plan for order #{$existing->order_id} conflicts with the requested parameters.");
        }
    }
}
