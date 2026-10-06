<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\Enums\FinancialAccountCode;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\FinancialPostingException;
use Webkul\Marketplace\Exceptions\FinancialRecordImmutableException;
use Webkul\Marketplace\Exceptions\IdempotencyConflictException;
use Webkul\Marketplace\Models\FinancialEntry;
use Webkul\Marketplace\Models\FinancialTransaction;
use Webkul\Marketplace\Services\FinancialTransactionService;

uses(TestCase::class);

function postPhase12aTransaction(array $entries, string $key = 'test:posting:1', string $currency = 'EGP'): FinancialTransaction
{
    return app(FinancialTransactionService::class)->post(
        eventType: 'test.posting',
        sourceType: 'test',
        sourceId: 1,
        idempotencyKey: $key,
        entries: $entries,
        currency: $currency,
    );
}

function balancedPhase12aEntries(string|int $amount = '1000.00'): array
{
    return [
        [
            'account_code'  => FinancialAccountCode::COMPANY_RECEIVABLE,
            'debit_amount'  => $amount,
            'credit_amount' => 0,
        ],
        [
            'account_code'  => FinancialAccountCode::PLATFORM_REVENUE,
            'debit_amount'  => 0,
            'credit_amount' => $amount,
        ],
    ];
}

it('posts a balanced EGP transaction with fixed account codes', function () {
    $transaction = postPhase12aTransaction(balancedPhase12aEntries());
    $debits = $transaction->entries->sum(fn (FinancialEntry $entry) => EgpAmount::toMinorUnits((string) $entry->debit_amount));
    $credits = $transaction->entries->sum(fn (FinancialEntry $entry) => EgpAmount::toMinorUnits((string) $entry->credit_amount));

    expect($transaction->currency)->toBe(FinancialCurrency::EGP)
        ->and($transaction->entries)->toHaveCount(2)
        ->and($debits)->toBe(100000)
        ->and($credits)->toBe(100000);
});

it('rejects an unbalanced transaction', function () {
    $entries = balancedPhase12aEntries();
    $entries[1]['credit_amount'] = '900.00';

    expect(fn () => postPhase12aTransaction($entries))
        ->toThrow(FinancialPostingException::class);
});

it('rejects negative and zero-value entries', function () {
    $negative = balancedPhase12aEntries();
    $negative[0]['debit_amount'] = '-1.00';

    $zero = balancedPhase12aEntries();
    $zero[0]['debit_amount'] = 0;
    $zero[0]['credit_amount'] = 0;

    expect(fn () => postPhase12aTransaction($negative, 'test:negative'))
        ->toThrow(FinancialPostingException::class);

    expect(fn () => postPhase12aTransaction($zero, 'test:zero'))
        ->toThrow(FinancialPostingException::class);
});

it('rejects an entry with both debit and credit amounts', function () {
    $entries = balancedPhase12aEntries();
    $entries[0]['credit_amount'] = '1.00';

    expect(fn () => postPhase12aTransaction($entries))
        ->toThrow(FinancialPostingException::class);
});

it('rejects unsupported currencies and account codes', function () {
    expect(fn () => postPhase12aTransaction(balancedPhase12aEntries(), 'test:currency', 'USD'))
        ->toThrow(FinancialPostingException::class);

    $entries = balancedPhase12aEntries();
    $entries[0]['account_code'] = 'unconfigured_account';

    expect(fn () => postPhase12aTransaction($entries, 'test:account'))
        ->toThrow(FinancialPostingException::class);
});

it('rounds EGP amounts half-up to two minor digits without float arithmetic', function () {
    $transaction = postPhase12aTransaction(balancedPhase12aEntries('1.005'), 'test:rounding');

    expect((string) $transaction->entries->first()->debit_amount)->toBe('1.0100')
        ->and((string) $transaction->entries->last()->credit_amount)->toBe('1.0100');
});

it('returns the existing transaction for an identical idempotent retry', function () {
    $first = postPhase12aTransaction(balancedPhase12aEntries(), 'test:idempotent');
    $retry = postPhase12aTransaction(balancedPhase12aEntries(), 'test:idempotent');

    expect($retry->id)->toBe($first->id)
        ->and(FinancialTransaction::where('idempotency_key', 'test:idempotent')->count())->toBe(1)
        ->and(FinancialEntry::where('financial_transaction_id', $first->id)->count())->toBe(2);
});

it('rejects a conflicting reuse of an idempotency key', function () {
    postPhase12aTransaction(balancedPhase12aEntries(), 'test:idempotency-conflict');

    expect(fn () => postPhase12aTransaction(
        balancedPhase12aEntries('2000.00'),
        'test:idempotency-conflict'
    ))->toThrow(IdempotencyConflictException::class);
});

it('rolls back the transaction and earlier entries if an entry insert fails', function () {
    $transactionsBefore = DB::table('marketplace_financial_transactions')->count();
    $entriesBefore = DB::table('marketplace_financial_entries')->count();

    $entries = balancedPhase12aEntries();
    $entries[1]['vendor_id'] = 4294967295;

    expect(fn () => postPhase12aTransaction($entries, 'test:atomic-rollback'))
        ->toThrow(QueryException::class);

    expect(DB::table('marketplace_financial_transactions')->count())->toBe($transactionsBefore)
        ->and(DB::table('marketplace_financial_entries')->count())->toBe($entriesBefore);
});

it('supports multiple balanced credits in one transaction', function () {
    $transaction = postPhase12aTransaction([
        [
            'account_code'  => FinancialAccountCode::COMPANY_RECEIVABLE,
            'debit_amount'  => '1000.00',
            'credit_amount' => 0,
        ],
        [
            'account_code'  => FinancialAccountCode::PLATFORM_REVENUE,
            'debit_amount'  => 0,
            'credit_amount' => '500.00',
        ],
        [
            'account_code'  => FinancialAccountCode::VENDOR_PAYABLE,
            'debit_amount'  => 0,
            'credit_amount' => '500.00',
        ],
    ], 'test:multiple-entries');
    $debits = $transaction->entries->sum(fn (FinancialEntry $entry) => EgpAmount::toMinorUnits((string) $entry->debit_amount));
    $credits = $transaction->entries->sum(fn (FinancialEntry $entry) => EgpAmount::toMinorUnits((string) $entry->credit_amount));

    expect($transaction->entries)->toHaveCount(3)
        ->and($debits)->toBe(100000)
        ->and($credits)->toBe(100000);
});

it('prevents updates and deletes through the financial models', function () {
    $transaction = postPhase12aTransaction(balancedPhase12aEntries(), 'test:immutable');
    $entry = $transaction->entries->first();

    expect(fn () => $transaction->forceFill(['event_type' => 'changed'])->save())
        ->toThrow(FinancialRecordImmutableException::class);

    expect(fn () => $entry->delete())
        ->toThrow(FinancialRecordImmutableException::class);
});
