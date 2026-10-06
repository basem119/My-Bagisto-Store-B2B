<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Database\Eloquent\Model;
use Webkul\Marketplace\Exceptions\MarketplaceCreditException;
use Webkul\Marketplace\Models\CompanyPaymentPolicy;

/**
 * Manages the single, admin-defined payment policy per company. Enforced at
 * the database level via a UNIQUE constraint on company_id; this service only
 * ever upserts that one row.
 */
class MarketplaceCompanyPaymentPolicyService
{
    public function findForCompany(Model $company): ?CompanyPaymentPolicy
    {
        return CompanyPaymentPolicy::query()->where('company_id', $company->id)->first();
    }

    public function createOrUpdate(Model $company, int $maximumTermDays, bool $installmentsAllowed, int $maximumInstallments, bool $isActive = true): CompanyPaymentPolicy
    {
        if ($maximumTermDays < 0) {
            throw new MarketplaceCreditException('The maximum payment term cannot be negative.');
        }

        if ($maximumInstallments < 1) {
            throw new MarketplaceCreditException('The maximum installment count must be at least 1.');
        }

        $policy = CompanyPaymentPolicy::query()->firstOrNew(['company_id' => $company->id]);

        $policy->forceFill([
            'company_id'           => $company->id,
            'maximum_term_days'    => $maximumTermDays,
            'installments_allowed' => $installmentsAllowed,
            'maximum_installments' => $maximumInstallments,
            'is_active'            => $isActive,
        ])->save();

        return $policy;
    }
}
