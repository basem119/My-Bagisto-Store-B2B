<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\B2BSuite\Helpers\CreditManager;
use Webkul\B2BSuite\Repositories\CompanyCreditRepository;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Enums\InstallmentStatus;
use Webkul\Marketplace\Enums\PaymentPlanStatus;
use Webkul\Marketplace\Exceptions\MarketplaceCreditException;
use Webkul\Marketplace\Models\Payment;
use Webkul\Marketplace\Models\PaymentAllocation;
use Webkul\Marketplace\Models\PaymentInstallment;
use Webkul\Marketplace\Models\PaymentPlan;

/**
 * Manual, admin-recorded company payments and their allocation to payment
 * installments. Recording a payment immediately (atomically) reduces the
 * company's B2B outstanding balance via the existing CreditManager::reimburse()
 * mechanism — this represents "how much the company has paid us in total" and
 * is independent of which installment(s) the payment is later allocated to.
 * Allocation only updates Marketplace-level installment bookkeeping; it never
 * touches B2B credit a second time, to avoid double-counting.
 */
class MarketplacePaymentService
{
    public function __construct(
        protected CreditManager $creditManager,
        protected CompanyCreditRepository $companyCreditRepository,
    ) {}

    public function recordPayment(
        Model $company,
        string $amount,
        string $paymentDate,
        ?string $reference = null,
        ?string $paymentMethod = null,
        ?string $notes = null,
        ?int $recordedByAdminId = null,
    ): Payment {
        $amountMinor = EgpAmount::toMinorUnits($amount);

        if ($amountMinor <= 0) {
            throw new MarketplaceCreditException('A recorded payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($company, $amountMinor, $paymentDate, $reference, $paymentMethod, $notes, $recordedByAdminId) {
            $credit = $this->creditManager->find($company->id);

            if (! $credit) {
                throw new MarketplaceCreditException("Company #{$company->id} does not have a credit facility.");
            }

            $credit = $this->companyCreditRepository->findForUpdate($credit->id);

            if (! $credit || ! $credit->status) {
                throw new MarketplaceCreditException("Company #{$company->id}'s credit facility is not active.");
            }

            if ($credit->credit_currency_code !== FinancialCurrency::EGP->value) {
                throw new MarketplaceCreditException('Marketplace company credit facilities must use EGP.');
            }

            if (str_starts_with((string) $credit->outstanding_balance, '-')) {
                throw new MarketplaceCreditException('A payment cannot be recorded against a negative B2B balance.');
            }

            if (($amountMinor * 100) > EgpAmount::toPrecisionUnits((string) $credit->outstanding_balance)) {
                throw new MarketplaceCreditException('A recorded payment cannot exceed the company credit outstanding balance.');
            }

            $payment = new Payment;
            $payment->forceFill([
                'company_id'           => $company->id,
                'currency'             => FinancialCurrency::EGP->value,
                'amount'               => EgpAmount::toDatabaseDecimal($amountMinor),
                'allocated_amount'     => EgpAmount::toDatabaseDecimal(0),
                'payment_date'         => $paymentDate,
                'reference'            => $reference,
                'payment_method'       => $paymentMethod,
                'notes'                => $notes,
                'recorded_by_admin_id' => $recordedByAdminId,
            ])->save();

            $this->creditManager->reimburse($credit, (float) EgpAmount::toDatabaseDecimal($amountMinor), [
                'reference' => $reference,
                'comment'   => $notes,
            ], [
                'type' => 'admin',
                'id'   => $recordedByAdminId,
            ]);

            return $payment;
        });
    }

    /**
     * Allocates part (or all) of a recorded payment to a specific installment.
     * Rejects any allocation that would exceed either the payment's remaining
     * unallocated amount or the installment's remaining owed amount — no
     * customer wallet/advance-credit concept exists, overpayment is simply
     * rejected.
     */
    public function allocate(Payment $payment, PaymentInstallment $installment, string $amount): PaymentAllocation
    {
        $amountMinor = EgpAmount::toMinorUnits($amount);

        if ($amountMinor <= 0) {
            throw new MarketplaceCreditException('An allocation amount must be greater than zero.');
        }

        return DB::transaction(function () use ($payment, $installment, $amountMinor) {
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $lockedInstallment = PaymentInstallment::query()->whereKey($installment->id)->lockForUpdate()->firstOrFail();
            $paymentPlan = PaymentPlan::query()->whereKey($lockedInstallment->payment_plan_id)->firstOrFail();

            if ((int) $lockedPayment->company_id !== (int) $paymentPlan->company_id) {
                throw new MarketplaceCreditException('A payment can only be allocated to an installment belonging to the same company.');
            }

            $paymentRemainingMinor = EgpAmount::toMinorUnits((string) $lockedPayment->amount) - EgpAmount::toMinorUnits((string) $lockedPayment->allocated_amount);
            $installmentRemainingMinor = EgpAmount::toMinorUnits((string) $lockedInstallment->amount) - EgpAmount::toMinorUnits((string) $lockedInstallment->paid_amount);

            if ($amountMinor > $paymentRemainingMinor) {
                throw new MarketplaceCreditException("Allocation exceeds payment #{$lockedPayment->id}'s remaining unallocated amount.");
            }

            if ($amountMinor > $installmentRemainingMinor) {
                throw new MarketplaceCreditException("Allocation exceeds installment #{$lockedInstallment->id}'s remaining owed amount.");
            }

            $allocation = new PaymentAllocation;
            $allocation->forceFill([
                'payment_id'     => $lockedPayment->id,
                'installment_id' => $lockedInstallment->id,
                'currency'       => FinancialCurrency::EGP->value,
                'amount'         => EgpAmount::toDatabaseDecimal($amountMinor),
            ])->save();

            $lockedPayment->forceFill([
                'allocated_amount' => EgpAmount::toDatabaseDecimal(
                    EgpAmount::toMinorUnits((string) $lockedPayment->allocated_amount) + $amountMinor
                ),
            ])->save();

            $newPaidMinor = EgpAmount::toMinorUnits((string) $lockedInstallment->paid_amount) + $amountMinor;
            $installmentTotalMinor = EgpAmount::toMinorUnits((string) $lockedInstallment->amount);

            $lockedInstallment->forceFill([
                'paid_amount' => EgpAmount::toDatabaseDecimal($newPaidMinor),
                'status'      => $newPaidMinor >= $installmentTotalMinor
                    ? InstallmentStatus::PAID->value
                    : InstallmentStatus::PARTIALLY_PAID->value,
            ])->save();

            $this->completePlanIfFullyPaid($lockedInstallment->payment_plan_id);

            return $allocation;
        });
    }

    private function completePlanIfFullyPaid(int $paymentPlanId): void
    {
        $allPaid = ! PaymentInstallment::query()
            ->where('payment_plan_id', $paymentPlanId)
            ->where('status', '!=', InstallmentStatus::PAID->value)
            ->exists();

        if ($allPaid) {
            PaymentPlan::query()->whereKey($paymentPlanId)->update(['status' => PaymentPlanStatus::COMPLETED->value]);
        }
    }
}
