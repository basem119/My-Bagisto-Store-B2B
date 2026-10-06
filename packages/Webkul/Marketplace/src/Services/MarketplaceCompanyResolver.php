<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Database\Eloquent\Model;
use Webkul\B2BSuite\Helpers\CreditManager;
use Webkul\Marketplace\Exceptions\MarketplaceFinancializationException;

/**
 * Resolves a B2B company without choosing arbitrarily among memberships.
 */
class MarketplaceCompanyResolver
{
    public function __construct(
        protected CreditManager $creditManager,
    ) {}

    public function resolve(?Model $customer, int|string|null $cartCompanyId = null): Model
    {
        if (! $customer) {
            throw new MarketplaceFinancializationException('A Marketplace order requires an authenticated B2B company customer.');
        }

        if (($customer->type ?? null) === 'company') {
            $company = $customer;
        } else {
            if (! method_exists($customer, 'companies')) {
                throw new MarketplaceFinancializationException('The authenticated customer does not expose the B2B company relationship.');
            }

            $companies = $customer->companies()->get();

            if ($companies->count() !== 1) {
                throw new MarketplaceFinancializationException(
                    $companies->isEmpty()
                        ? 'The authenticated customer does not belong to a B2B company.'
                        : 'The authenticated customer belongs to multiple B2B companies; Marketplace financialization is ambiguous.'
                );
            }

            $company = $this->creditManager->companyOf($customer);

            if (! $company || (int) $company->id !== (int) $companies->first()->id) {
                throw new MarketplaceFinancializationException('B2B company resolution was inconsistent.');
            }
        }

        if ($cartCompanyId !== null && (int) $cartCompanyId !== (int) $company->id) {
            throw new MarketplaceFinancializationException('The cart company does not match the authenticated customer company.');
        }

        return $company;
    }
}