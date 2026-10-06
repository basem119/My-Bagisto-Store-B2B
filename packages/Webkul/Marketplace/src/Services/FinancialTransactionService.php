<?php

namespace Webkul\Marketplace\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\Marketplace\DataTypes\EgpAmount;
use Webkul\Marketplace\Enums\FinancialAccountCode;
use Webkul\Marketplace\Enums\FinancialCurrency;
use Webkul\Marketplace\Exceptions\FinancialPostingException;
use Webkul\Marketplace\Exceptions\IdempotencyConflictException;
use Webkul\Marketplace\Models\FinancialEntry;
use Webkul\Marketplace\Models\FinancialTransaction;

/**
 * The sole supported write boundary for Marketplace ledger postings.
 * A committed transaction is immutable; corrections are new reversals.
 */
class FinancialTransactionService
{
    private const CONTEXT_FIELDS = [
        'order_id',
        'order_item_id',
        'vendor_id',
        'company_id',
    ];

    /**
     * Create a balanced posting, or return the matching existing posting for
     * an idempotent retry. The database unique key handles concurrent retries.
     *
     * @param  array<int, array{account_code: FinancialAccountCode|string, debit_amount: int|string, credit_amount: int|string}>  $entries
     * @param  array{order_id?: int|string|null, order_item_id?: int|string|null, vendor_id?: int|string|null, company_id?: int|string|null}  $context
     */
    public function post(
        string $eventType,
        string $sourceType,
        int|string $sourceId,
        string $idempotencyKey,
        array $entries,
        array $context = [],
        FinancialCurrency|string $currency = FinancialCurrency::EGP,
        int|string|null $createdBy = null,
    ): FinancialTransaction {
        $currency = $this->resolveCurrency($currency);
        $eventType = $this->validateIdentifier($eventType, 'event type');
        $sourceType = $this->validateIdentifier($sourceType, 'source type');
        $sourceId = $this->normalizeId($sourceId, 'source id');
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $createdBy = $createdBy === null ? null : $this->normalizeId($createdBy, 'creator id');
        $context = $this->normalizeContext($context);

        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            throw new FinancialPostingException('A posting must contain at least one entry.');
        }

        $normalizedEntries = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($entries as $entry) {
            $normalizedEntry = $this->normalizeEntry($entry, $context);

            $totalDebits = $this->addMinorUnits($totalDebits, $normalizedEntry['debit_minor']);
            $totalCredits = $this->addMinorUnits($totalCredits, $normalizedEntry['credit_minor']);
            $normalizedEntries[] = $normalizedEntry;
        }

        if ($totalDebits === 0 || $totalDebits !== $totalCredits) {
            throw new FinancialPostingException('Total debits and credits must be equal and greater than zero.');
        }

        $posting = [
            'event_type'  => $eventType,
            'currency'    => $currency->value,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'context'     => $context,
            'entries'     => $normalizedEntries,
        ];

        try {
            return DB::transaction(function () use ($posting, $idempotencyKey, $createdBy): FinancialTransaction {
                $existing = FinancialTransaction::query()
                    ->with('entries')
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $this->reuseOrReject($existing, $posting);
                }

                $transaction = new FinancialTransaction;
                $transaction->forceFill([
                    'event_type'      => $posting['event_type'],
                    'currency'        => $posting['currency'],
                    'idempotency_key' => $idempotencyKey,
                    'source_type'     => $posting['source_type'],
                    'source_id'       => $posting['source_id'],
                    ...$posting['context'],
                    'created_by' => $createdBy,
                ])->save();

                foreach ($posting['entries'] as $entry) {
                    $financialEntry = new FinancialEntry;
                    $financialEntry->forceFill([
                        'financial_transaction_id' => $transaction->id,
                        'account_code'             => $entry['account_code'],
                        'debit_amount'             => EgpAmount::toDatabaseDecimal($entry['debit_minor']),
                        'credit_amount'            => EgpAmount::toDatabaseDecimal($entry['credit_minor']),
                        'currency'                 => $posting['currency'],
                        ...$entry['context'],
                    ])->save();
                }

                return $transaction->load('entries');
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = FinancialTransaction::query()
                ->with('entries')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return $this->reuseOrReject($existing, $posting);
        }
    }

    private function normalizeEntry(mixed $entry, array $headerContext): array
    {
        if (! is_array($entry)) {
            throw new FinancialPostingException('Each financial entry must be an array.');
        }

        $allowedKeys = [...self::CONTEXT_FIELDS, 'account_code', 'debit_amount', 'credit_amount'];

        if (array_diff(array_keys($entry), $allowedKeys) !== []) {
            throw new FinancialPostingException('A financial entry contains unsupported fields.');
        }

        if (! array_key_exists('account_code', $entry)) {
            throw new FinancialPostingException('Each entry requires a valid account code.');
        }

        $account = $entry['account_code'];

        if ($account instanceof FinancialAccountCode) {
            $account = $account->value;
        }

        if (! is_string($account) || ! ($account = FinancialAccountCode::tryFrom($account))) {
            throw new FinancialPostingException('The financial entry account code is invalid.');
        }

        if (! array_key_exists('debit_amount', $entry) || ! array_key_exists('credit_amount', $entry)) {
            throw new FinancialPostingException('Each entry requires debit_amount and credit_amount.');
        }

        $debit = EgpAmount::toMinorUnits($entry['debit_amount']);
        $credit = EgpAmount::toMinorUnits($entry['credit_amount']);

        if (($debit > 0) === ($credit > 0)) {
            throw new FinancialPostingException('An entry must have exactly one positive debit or credit amount.');
        }

        $entryContext = array_intersect_key($entry, array_flip(self::CONTEXT_FIELDS));
        $context = $this->normalizeContext(array_merge($headerContext, $entryContext));

        return [
            'account_code' => $account->value,
            'debit_minor'  => $debit,
            'credit_minor' => $credit,
            'context'      => $context,
        ];
    }

    private function normalizeContext(array $context): array
    {
        if (array_diff(array_keys($context), self::CONTEXT_FIELDS) !== []) {
            throw new FinancialPostingException('The posting context contains unsupported fields.');
        }

        $normalized = [];

        foreach (self::CONTEXT_FIELDS as $field) {
            $normalized[$field] = array_key_exists($field, $context) && $context[$field] !== null
                ? $this->normalizeId($context[$field], $field)
                : null;
        }

        if ($normalized['order_item_id'] !== null && $normalized['order_id'] === null) {
            throw new FinancialPostingException('An order item reference requires its order reference.');
        }

        return $normalized;
    }

    private function resolveCurrency(FinancialCurrency|string $currency): FinancialCurrency
    {
        if ($currency instanceof FinancialCurrency) {
            return $currency;
        }

        $resolved = FinancialCurrency::tryFrom($currency);

        if (! $resolved) {
            throw new FinancialPostingException('Only EGP financial postings are supported.');
        }

        return $resolved;
    }

    private function validateIdentifier(string $value, string $label): string
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value)) {
            throw new FinancialPostingException("The {$label} is invalid.");
        }

        return $value;
    }

    private function normalizeId(int|string $id, string $label): int
    {
        $value = (string) $id;

        if (! preg_match('/^[1-9]\d*$/D', $value) || strlen($value) > 10 || (float) $value > 4294967295) {
            throw new FinancialPostingException("The {$label} must be a positive unsigned integer.");
        }

        return (int) $value;
    }

    private function normalizeIdempotencyKey(string $key): string
    {
        if ($key === '' || trim($key) !== $key || strlen($key) > 191) {
            throw new FinancialPostingException('The idempotency key must contain 1 to 191 non-padded characters.');
        }

        return $key;
    }

    private function addMinorUnits(int $total, int $amount): int
    {
        if ($total > PHP_INT_MAX - $amount) {
            throw new FinancialPostingException('The posting total exceeds the supported integer range.');
        }

        return $total + $amount;
    }

    private function reuseOrReject(FinancialTransaction $existing, array $posting): FinancialTransaction
    {
        if (! hash_equals($this->fingerprint($this->existingPosting($existing)), $this->fingerprint($posting))) {
            throw new IdempotencyConflictException('The idempotency key has already been used for a different financial operation.');
        }

        return $existing;
    }

    private function existingPosting(FinancialTransaction $transaction): array
    {
        $entries = $transaction->entries->map(fn (FinancialEntry $entry) => [
            'account_code' => $entry->account_code->value,
            'debit_minor'  => EgpAmount::toMinorUnits((string) $entry->debit_amount),
            'credit_minor' => EgpAmount::toMinorUnits((string) $entry->credit_amount),
            'context'      => $this->normalizeContext([
                'order_id'      => $entry->order_id,
                'order_item_id' => $entry->order_item_id,
                'vendor_id'     => $entry->vendor_id,
                'company_id'    => $entry->company_id,
            ]),
        ])->all();

        return [
            'event_type'  => $transaction->event_type,
            'currency'    => $transaction->currency->value,
            'source_type' => $transaction->source_type,
            'source_id'   => (int) $transaction->source_id,
            'context'     => $this->normalizeContext([
                'order_id'      => $transaction->order_id,
                'order_item_id' => $transaction->order_item_id,
                'vendor_id'     => $transaction->vendor_id,
                'company_id'    => $transaction->company_id,
            ]),
            'entries' => $entries,
        ];
    }

    private function fingerprint(array $posting): string
    {
        $entries = array_map(function (array $entry): array {
            ksort($entry['context']);

            return $entry;
        }, $posting['entries']);

        usort($entries, fn (array $left, array $right) => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR),
            json_encode($right, JSON_THROW_ON_ERROR)
        ));

        $context = $posting['context'];
        ksort($context);

        return hash('sha256', json_encode([
            'event_type'  => $posting['event_type'],
            'currency'    => $posting['currency'],
            'source_type' => $posting['source_type'],
            'source_id'   => $posting['source_id'],
            'context'     => $context,
            'entries'     => $entries,
        ], JSON_THROW_ON_ERROR));
    }
}
