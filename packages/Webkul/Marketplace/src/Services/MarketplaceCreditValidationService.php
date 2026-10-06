<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Database\Eloquent\Model;
use Webkul\B2BSuite\Helpers\CreditManager;
use Webkul\B2BSuite\Models\CompanyCreditTransaction;
use Webkul\B2BSuite\Repositories\CompanyCreditRepository;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\MarketplaceCreditException;

/**
 * Read-only(ish) projected-exposure enforcement for Marketplace credit orders.
 *
 * Deliberately does NOT honour CompanyCredit::allow_exceed_limit: Marketplace
 * always enforces the hard credit limit regardless of that B2B-level flag
 * (see Phase 12C spec section 4). Mutation of the B2B outstanding balance
 * itself is never performed here — that remains the sole responsibility of
 * B2BSuite's own CreditManager (via its existing checkout/credit listeners
 * and MarketplacePaymentService::recordPayment()).
 */
class MarketplaceCreditValidationService
{
    public function __construct(
        protected CreditManager $creditManager,
        protected CompanyCreditRepository $companyCreditRepository,
    ) {}

    /**
     * Assert that outstanding + the given order amount does not exceed the
     * company's credit limit. Locks the company's credit row (via B2B's own
     * findForUpdate()) so concurrent orders for the same company serialize
     * against the same limit check. Must be called from within the same
     * database transaction that will go on to create the order/payment plan,
     * so the lock is held until that transaction commits.
     */
    public function assertProjectedExposureWithinLimit(Model $company, int $orderAmountMinorUnits, ?int $orderId = null): void
    {
        if (! (bool) core()->getConfigData('b2b.general.settings.active')) {
            throw new MarketplaceCreditException('The B2B Suite is not active.');
        }

        if (! $this->creditManager->isActive()) {
            throw new MarketplaceCreditException('The B2B credit feature is not active.');
        }

        $credit = $this->creditManager->find($company->id);

        if (! $credit) {
            throw new MarketplaceCreditException("Company #{$company->id} does not have a credit facility.");
        }

        $locked = $this->companyCreditRepository->findForUpdate($credit->id);

        if (! $locked || ! $locked->status) {
            throw new MarketplaceCreditException("Company #{$company->id}'s credit facility is not active.");
        }

        if ($locked->credit_currency_code !== FinancialCurrency::EGP->value) {
            throw new MarketplaceCreditException('Marketplace company credit facilities must use EGP.');
        }

        if (str_starts_with((string) $locked->outstanding_balance, '-')) {
            throw new MarketplaceCreditException('Negative B2B balances cannot be used as Marketplace advance credit.');
        }

        if ($orderAmountMinorUnits < 0) {
            throw new MarketplaceCreditException('A Marketplace order amount cannot be negative.');
        }

        $alreadyCharged = $orderId !== null && CompanyCreditTransaction::query()
            ->where('company_credit_id', $locked->id)
            ->where('operation', CompanyCreditTransaction::OPERATION_PURCHASED)
            ->where('order_id', $orderId)
            ->exists();

        $outstandingPrecision = EgpAmount::toPrecisionUnits((string) $locked->outstanding_balance);
        $limitPrecision = EgpAmount::toPrecisionUnits((string) $locked->credit_limit);

        $projectedPrecision = $alreadyCharged
            ? $outstandingPrecision
            : $outstandingPrecision + ($orderAmountMinorUnits * 100);

        if ($projectedPrecision > $limitPrecision) {
            throw new MarketplaceCreditException("Company #{$company->id}'s projected credit exposure exceeds its approved credit limit.");
        }
    }
}
