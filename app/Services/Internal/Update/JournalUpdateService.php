<?php

/**
 * JournalUpdateService.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Services\Internal\Update;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidDateException;
use Carbon\Exceptions\InvalidFormatException;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Events\TriggeredAuditLog;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Factory\TagFactory;
use FireflyIII\Factory\TransactionJournalMetaFactory;
use FireflyIII\Factory\TransactionTypeFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Services\Internal\Support\JournalServiceTrait;
use FireflyIII\Support\Facades\FireflyConfig;
use FireflyIII\Support\NullArrayObject;
use FireflyIII\Validation\AccountValidator;
use Illuminate\Support\Facades\Log;

/**
 * Class to centralise code that updates a journal given the input by system.
 *
 * Class JournalUpdateService
 */
class JournalUpdateService
{
    use JournalServiceTrait;

    private BillRepositoryInterface $billRepository;
    private CurrencyRepositoryInterface $currencyRepository;
    private TransactionGroupRepositoryInterface $transactionGroupRepository;
    private array $data;
    private ?Account $destinationAccount;
    private ?Transaction $destinationTransaction;
    private array $metaDate;
    private array $metaString;
    private ?Account $sourceAccount;
    private ?Transaction $sourceTransaction;
    private ?TransactionGroup $transactionGroup;
    private ?TransactionJournal $transactionJournal;
    private string $startCompareHash = '';

    /**
     * JournalUpdateService constructor.
     */
    public function __construct()
    {
        $this->destinationAccount         = null;
        $this->destinationTransaction     = null;
        $this->sourceAccount              = null;
        $this->sourceTransaction          = null;
        $this->transactionGroup           = null;
        $this->transactionJournal         = null;
        $this->billRepository             = app(BillRepositoryInterface::class);
        $this->categoryRepository         = app(CategoryRepositoryInterface::class);
        $this->budgetRepository           = app(BudgetRepositoryInterface::class);
        $this->tagFactory                 = app(TagFactory::class);
        $this->accountRepository          = app(AccountRepositoryInterface::class);
        $this->currencyRepository         = app(CurrencyRepositoryInterface::class);
        $this->transactionGroupRepository = app(TransactionGroupRepositoryInterface::class);
        $this->metaString                 = [
            'sepa_cc',
            'sepa_ct_op',
            'sepa_ct_id',
            'sepa_db',
            'sepa_country',
            'sepa_ep',
            'sepa_ci',
            'sepa_batch_id',
            'recurrence_id',
            'internal_reference',
            'bunq_payment_id',
            'external_id',
            'external_url',
        ];
        $this->metaDate                   = ['interest_date', 'book_date', 'process_date', 'due_date', 'payment_date',
            'invoice_date', ];
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function setTransactionGroup(TransactionGroup $transactionGroup): void
    {
        $this->transactionGroup       = $transactionGroup;
        $this->billRepository->setUser($transactionGroup->user);
        $this->categoryRepository->setUser($transactionGroup->user);
        $this->budgetRepository->setUser($transactionGroup->user);
        $this->tagFactory->setUser($transactionGroup->user);
        $this->accountRepository->setUser($transactionGroup->user);
        $this->transactionGroupRepository->setUser($transactionGroup->user);
        $this->destinationAccount     = null;
        $this->destinationTransaction = null;
        $this->sourceAccount          = null;
        $this->sourceTransaction      = null;
        $this->startCompareHash       = $this->transactionGroupRepository->getCompareHash($transactionGroup);
    }

    public function setTransactionJournal(TransactionJournal $transactionJournal): void
    {
        $this->transactionJournal = $transactionJournal;
    }

    public function update(): void
    {
        Log::debug(sprintf('Now in %s', __METHOD__));
        Log::debug(sprintf('Now in JournalUpdateService for journal #%d.', $this->transactionJournal->id));

        $this->data['reconciled'] = array_key_exists('reconciled', $this->data) ? $this->data['reconciled'] : null;

        // can we update account data using the new type?
        if ($this->hasValidAccounts()) {
            Log::info('Account info is valid, now update.');
            // update accounts:
            $this->updateAccounts();

            // then also update transaction journal type ID:
            $this->updateType();
            $this->transactionJournal->refresh();
        }
        // find and update bill, if possible.
        $this->updateBill();

        // update journal fields.
        $this->updateField('description');
        $this->updateField('date');
        $this->updateField('order');
        $this->transactionJournal->save();
        $this->transactionJournal->refresh();

        $this->updateCategory();
        $this->updateBudget();
        $this->updateTags();
        $this->updateReconciled();
        $this->updateNotes();
        $this->updateMeta();
        $this->updateCurrency();
        $this->updateAmount();
        $this->updateForeignAmount();

        app('preferences')->mark();

        $this->transactionJournal->refresh();
        Log::debug('Done with update journal routine');
    }

    private function hasValidAccounts(): bool
    {
        Log::debug('Now in hasValidAccounts().');

        return $this->hasValidSourceAccount() && $this->hasValidDestinationAccount();
    }

    private function hasValidSourceAccount(): bool
    {
        $sourceId     = $this->data['source_id'] ?? null;
        $sourceName   = $this->data['source_name'] ?? null;
        Log::debug(sprintf('Now in hasValidSourceAccount("%s","%s").', $sourceId, $sourceName));

        if (!$this->hasFields(['source_id', 'source_name'])) {
            $origSourceAccount = $this->getOriginalSourceAccount();
            $sourceId          = $origSourceAccount->id;
            $sourceName        = $origSourceAccount->name;
        }

        // make new account validator.
        $expectedType = $this->getExpectedType();
        Log::debug(sprintf('(a) Expected type (new or unchanged) is %s', $expectedType));

        // make a new validator.
        /** @var AccountValidator $validator */
        $validator    = app(AccountValidator::class);
        $validator->setTransactionType($expectedType);
        $validator->setUser($this->transactionJournal->user);

        $result       = $validator->validateSource(['id' => $sourceId, 'name' => $sourceName]);
        Log::debug(
            sprintf('hasValidSourceAccount(%d, "%s") will return %s', $sourceId, $sourceName, var_export($result, true))
        );

        // TODO typeoverrule the account validator may have a different opinion on the transaction type.

        // validate submitted info:
        return $result;
    }

    private function hasFields(array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $this->data)) {
                return true;
            }
        }

        return false;
    }

    private function getOriginalSourceAccount(): Account
    {
        if (!$this->sourceAccount instanceof Account) {
            $source              = $this->getSourceTransaction();
            $this->sourceAccount = $source->account;
        }

        return $this->sourceAccount;
    }

    private function getSourceTransaction(): Transaction
    {
        if (!$this->sourceTransaction instanceof Transaction) {
            /** @var null|Transaction $result */
            $result                  = $this->transactionJournal->transactions()->with(['account'])->where('amount', '<', 0)->first();
            $this->sourceTransaction = $result;
        }
        Log::debug(sprintf('getSourceTransaction: %s', $this->sourceTransaction->amount));

        return $this->sourceTransaction;
    }

    /**
     * This method returns the current or expected type of the journal (in case of a change) based on the data in the
     * array.
     *
     * If the array contains key 'type' and the value is correct, this is returned. Otherwise, the original type is
     * returned.
     */
    private function getExpectedType(): string
    {
        Log::debug('Now in getExpectedType()');
        if ($this->hasFields(['type'])) {
            return ucfirst('opening-balance' === $this->data['type'] ? 'opening balance' : $this->data['type']);
        }

        return $this->transactionJournal->transactionType->type;
    }

    private function hasValidDestinationAccount(): bool
    {
        Log::debug('Now in hasValidDestinationAccount().');
        $destId            = $this->data['destination_id'] ?? null;
        $destName          = $this->data['destination_name'] ?? null;

        if (!$this->hasFields(['destination_id', 'destination_name'])) {
            Log::debug('No destination info submitted, grab the original data.');
            $destination = $this->getOriginalDestinationAccount();
            $destId      = $destination->id;
            $destName    = $destination->name;
        }

        // make new account validator.
        $expectedType      = $this->getExpectedType();
        Log::debug(sprintf('(b) Expected type (new or unchanged) is %s', $expectedType));

        // make a new validator.
        /** @var AccountValidator $validator */
        $validator         = app(AccountValidator::class);
        $validator->setTransactionType($expectedType);
        $validator->setUser($this->transactionJournal->user);
        $validator->source = $this->getValidSourceAccount();
        $result            = $validator->validateDestination(['id' => $destId, 'name' => $destName]);
        Log::debug(
            sprintf(
                'hasValidDestinationAccount(%d, "%s") will return %s',
                $destId,
                $destName,
                var_export($result, true)
            )
        );

        // TODO typeOverrule: the account validator may have another opinion on the transaction type.

        // validate submitted info:
        return $result;
    }

    private function getOriginalDestinationAccount(): Account
    {
        if (!$this->destinationAccount instanceof Account) {
            $destination              = $this->getDestinationTransaction();
            $this->destinationAccount = $destination->account;
        }

        return $this->destinationAccount;
    }

    /**
     * Get destination transaction.
     */
    private function getDestinationTransaction(): Transaction
    {
        if (!$this->destinationTransaction instanceof Transaction) {
            /** @var null|Transaction $result */
            $result                       = $this->transactionJournal->transactions()->where('amount', '>', 0)->first();
            $this->destinationTransaction = $result;
        }

        return $this->destinationTransaction;
    }

    /**
     * Does a validation and returns the source account. This method will break if the source isn't really valid.
     */
    private function getValidSourceAccount(): Account
    {
        Log::debug('Now in getValidSourceAccount().');

        if (!$this->hasFields(['source_id', 'source_name'])) {
            return $this->getOriginalSourceAccount();
        }

        $sourceInfo   = [
            'id'     => (int)($this->data['source_id'] ?? null),
            'name'   => $this->data['source_name'] ?? null,
            'iban'   => $this->data['source_iban'] ?? null,
            'number' => $this->data['source_number'] ?? null,
            'bic'    => $this->data['source_bic'] ?? null,
        ];

        $expectedType = $this->getExpectedType();

        try {
            $result = $this->getAccount($expectedType, 'source', $sourceInfo);
        } catch (FireflyException $e) {
            Log::error(sprintf('Cant get the valid source account: %s', $e->getMessage()));

            $result = $this->getOriginalSourceAccount();
        }

        Log::debug(sprintf('getValidSourceAccount() will return #%d ("%s")', $result->id, $result->name));

        return $result;
    }

    /**
     * Will update the source and destination accounts of this journal. Assumes they are valid.
     */
    private function updateAccounts(): void
    {
        $source                = $this->getValidSourceAccount();
        $destination           = $this->getValidDestinationAccount();

        // cowardly refuse to update if both accounts are the same.
        if ($source->id === $destination->id) {
            Log::error(sprintf('Source + dest accounts are equal (%d, "%s")', $source->id, $source->name));

            return;
        }

        $origSourceTransaction = $this->getSourceTransaction();
        $origSourceTransaction->account()->associate($source);
        $origSourceTransaction->save();

        $destTransaction       = $this->getDestinationTransaction();
        $destTransaction->account()->associate($destination);
        $destTransaction->save();

        // refresh transactions.
        $this->sourceTransaction->refresh();
        $this->destinationTransaction->refresh();
        Log::debug(sprintf('Will set source to #%d ("%s")', $source->id, $source->name));
        Log::debug(sprintf('Will set dest to #%d ("%s")', $destination->id, $destination->name));
    }

    /**
     * Does a validation and returns the destination account. This method will break if the dest isn't really valid.
     */
    private function getValidDestinationAccount(): Account
    {
        Log::debug('Now in getValidDestinationAccount().');

        if (!$this->hasFields(['destination_id', 'destination_name'])) {
            return $this->getOriginalDestinationAccount();
        }

        $destInfo     = [
            'id'     => (int)($this->data['destination_id'] ?? null),
            'name'   => $this->data['destination_name'] ?? null,
            'iban'   => $this->data['destination_iban'] ?? null,
            'number' => $this->data['destination_number'] ?? null,
            'bic'    => $this->data['destination_bic'] ?? null,
        ];

        // make new account validator.
        $expectedType = $this->getExpectedType();
        Log::debug(sprintf('(c) Expected type (new or unchanged) is %s', $expectedType));

        try {
            $result = $this->getAccount($expectedType, 'destination', $destInfo);
        } catch (FireflyException $e) {
            Log::error(sprintf('getValidDestinationAccount() threw unexpected error: %s', $e->getMessage()));
            $result = $this->getOriginalDestinationAccount();
        }

        return $result;
    }

    /**
     * Updates journal transaction type.
     */
    private function updateType(): void
    {
        Log::debug('Now in updateType()');
        if ($this->hasFields(['type'])) {
            $type        = 'opening-balance' === $this->data['type'] ? 'opening balance' : $this->data['type'];
            Log::debug(
                sprintf(
                    'Trying to change journal #%d from a %s to a %s.',
                    $this->transactionJournal->id,
                    $this->transactionJournal->transactionType->type,
                    $type
                )
            );

            /** @var TransactionTypeFactory $typeFactory */
            $typeFactory = app(TransactionTypeFactory::class);
            $result      = $typeFactory->find($this->data['type']);
            if (null !== $result) {
                Log::debug('Changed transaction type!');
                $this->transactionJournal->transaction_type_id = $result->id;
                $this->transactionJournal->save();

                return;
            }

            return;
        }
        Log::debug('No type field present.');
    }

    /**
     * Update journal bill information.
     */
    private function updateBill(): void
    {
        $type = $this->transactionJournal->transactionType->type;
        if ((
            array_key_exists('bill_id', $this->data)
                || array_key_exists('bill_name', $this->data)
        )
            && TransactionTypeEnum::WITHDRAWAL->value === $type
        ) {
            $billId                            = (int)($this->data['bill_id'] ?? 0);
            $billName                          = (string)($this->data['bill_name'] ?? '');
            $bill                              = $this->billRepository->findBill($billId, $billName);
            $this->transactionJournal->bill_id = $bill?->id;
            Log::debug('Updated bill ID');
        }
    }

    /**
     * Update journal generic field. Cannot be set to NULL.
     */
    private function updateField(string $fieldName): void
    {
        if (array_key_exists($fieldName, $this->data) && '' !== (string)$this->data[$fieldName]) {
            $value                                  = $this->data[$fieldName];

            if ('date' === $fieldName) {
                if (!$value instanceof Carbon) {
                    $value = new Carbon($value);
                }

                $value->setTimezone(config('app.timezone'));
                // 2024-11-22, overrule timezone with UTC and store it as UTC.
                if (true === FireflyConfig::get('utc', false)->data) {
                    $value->setTimezone('UTC');
                }

                // do some parsing.
                Log::debug(sprintf('Create date value from string "%s".', $value));
                $this->transactionJournal->date_tz = $value->format('e');
            }
            event(
                new TriggeredAuditLog(
                    $this->transactionJournal->user,
                    $this->transactionJournal,
                    sprintf('update_%s', $fieldName),
                    $this->transactionJournal->{$fieldName}, // @phpstan-ignore-line
                    $value
                )
            );

            $this->transactionJournal->{$fieldName} = $value; // @phpstan-ignore-line
            Log::debug(sprintf('Updated %s', $fieldName));
        }
    }

    private function updateCategory(): void
    {
        // update category
        if ($this->hasFields(['category_id', 'category_name'])) {
            Log::debug('Will update category.');

            $this->storeCategory($this->transactionJournal, new NullArrayObject($this->data));
        }
    }

    private function updateBudget(): void
    {
        // update budget
        if ($this->hasFields(['budget_id', 'budget_name'])) {
            Log::debug('Will update budget.');
            $this->storeBudget($this->transactionJournal, new NullArrayObject($this->data));
        }
        // is transfer? remove budget
        if (TransactionTypeEnum::TRANSFER->value === $this->transactionJournal->transactionType->type) {
            $this->transactionJournal->budgets()->sync([]);
        }
    }

    private function updateTags(): void
    {
        if ($this->hasFields(['tags'])) {
            Log::debug('Will update tags.');
            $tags = $this->data['tags'] ?? null;
            $this->storeTags($this->transactionJournal, $tags);
        }
    }

    private function updateReconciled(): void
    {
        if (array_key_exists('reconciled', $this->data) && is_bool($this->data['reconciled'])) {
            $this->transactionJournal->transactions()->update(['reconciled' => $this->data['reconciled']]);
        }
    }

    private function updateNotes(): void
    {
        // update notes.
        if ($this->hasFields(['notes'])) {
            $notes = '' === (string)$this->data['notes'] ? null : $this->data['notes'];
            $this->storeNotes($this->transactionJournal, $notes);
        }
    }

    private function updateMeta(): void
    {
        // update meta fields.
        // first string
        if ($this->hasFields($this->metaString)) {
            Log::debug('Meta string fields are present.');
            $this->updateMetaFields();
        }

        // then date fields.
        if ($this->hasFields($this->metaDate)) {
            Log::debug('Meta date fields are present.');
            $this->updateMetaDateFields();
        }
    }

    private function updateMetaFields(): void
    {
        /** @var TransactionJournalMetaFactory $factory */
        $factory = app(TransactionJournalMetaFactory::class);

        foreach ($this->metaString as $field) {
            if ($this->hasFields([$field])) {
                $value = '' === $this->data[$field] ? null : $this->data[$field];
                Log::debug(sprintf('Field "%s" is present ("%s"), try to update it.', $field, $value));
                $set   = [
                    'journal' => $this->transactionJournal,
                    'name'    => $field,
                    'data'    => $value,
                ];
                $factory->updateOrCreate($set);
            }
        }
    }

    private function updateMetaDateFields(): void
    {
        /** @var TransactionJournalMetaFactory $factory */
        $factory = app(TransactionJournalMetaFactory::class);

        foreach ($this->metaDate as $field) {
            if ($this->hasFields([$field])) {
                try {
                    $value = '' === (string)$this->data[$field] ? null : new Carbon($this->data[$field]);
                } catch (InvalidDateException|InvalidFormatException $e) { // @phpstan-ignore-line
                    Log::debug(sprintf('%s is not a valid date value: %s', $this->data[$field], $e->getMessage()));

                    return;
                }
                Log::debug(sprintf('Field "%s" is present ("%s"), try to update it.', $field, $value));
                $set = [
                    'journal' => $this->transactionJournal,
                    'name'    => $field,
                    'data'    => $value,
                ];
                $factory->updateOrCreate($set);
                // also set date with timezone.
                $set = [
                    'journal' => $this->transactionJournal,
                    'name'    => sprintf('%s_tz', $field),
                    'data'    => $value?->format('e'),
                ];
                $factory->updateOrCreate($set);
            }
        }
    }

    private function updateCurrency(): void
    {
        // update transactions.
        if (!$this->hasFields(['currency_id', 'currency_code'])) {
            return;
        }
        $currencyId                                        = $this->data['currency_id'] ?? null;
        $currencyCode                                      = $this->data['currency_code'] ?? null;
        $currency                                          = $this->currencyRepository->findCurrency($currencyId, $currencyCode);
        // update currency everywhere.
        $this->transactionJournal->transaction_currency_id = $currency->id;
        $this->transactionJournal->save();

        $source                                            = $this->getSourceTransaction();
        $source->transaction_currency_id                   = $currency->id;
        $source->save();

        $dest                                              = $this->getDestinationTransaction();
        $dest->transaction_currency_id                     = $currency->id;
        $dest->save();

        // refresh transactions.
        $this->sourceTransaction->refresh();
        $this->destinationTransaction->refresh();
        Log::debug(sprintf('Updated currency to #%d (%s)', $currency->id, $currency->code));
    }

    private function updateAmount(): void
    {
        Log::debug(sprintf('Now in %s', __METHOD__));
        if (!$this->hasFields(['amount'])) {
            return;
        }

        $value                                = $this->data['amount'] ?? '';
        Log::debug(sprintf('Amount is now "%s"', $value));

        try {
            $amount = $this->getAmount($value);
        } catch (FireflyException $e) {
            Log::debug(sprintf('getAmount("%s") returns error: %s', $value, $e->getMessage()));

            return;
        }
        $origSourceTransaction                = $this->getSourceTransaction();
        $origSourceTransaction->amount        = app('steam')->negative($amount);
        $origSourceTransaction->balance_dirty = true;
        $origSourceTransaction->save();
        $destTransaction                      = $this->getDestinationTransaction();
        $destTransaction->amount              = app('steam')->positive($amount);
        $destTransaction->balance_dirty       = true;
        $destTransaction->save();
        // refresh transactions.
        $this->sourceTransaction->refresh();
        $this->destinationTransaction->refresh();
        Log::debug(sprintf('Updated amount to "%s"', $amount));
    }

    private function updateForeignAmount(): void
    {
        // amount, foreign currency.
        if (!$this->hasFields(['foreign_currency_id', 'foreign_currency_code', 'foreign_amount'])) {
            return;
        }

        $amount          = $this->data['foreign_amount'] ?? null;
        $foreignAmount   = $this->getForeignAmount($amount);
        $source          = $this->getSourceTransaction();
        $dest            = $this->getDestinationTransaction();
        $foreignCurrency = $source->foreignCurrency;

        // find currency in data array
        $newForeignId    = $this->data['foreign_currency_id'] ?? null;
        $newForeignCode  = $this->data['foreign_currency_code'] ?? null;
        $foreignCurrency = $this->currencyRepository->findCurrencyNull($newForeignId, $newForeignCode)
            ?? $foreignCurrency;

        // not the same as normal currency
        if (null !== $foreignCurrency && $foreignCurrency->id === $this->transactionJournal->transaction_currency_id) {
            Log::error(sprintf('Foreign currency is equal to normal currency (%s)', $foreignCurrency->code));

            return;
        }

        // add foreign currency info to source and destination if possible.
        if (null !== $foreignCurrency && null !== $foreignAmount) {
            $source->foreign_currency_id = $foreignCurrency->id;
            $source->foreign_amount      = app('steam')->negative($foreignAmount);
            $source->save();

            // if the transaction is a TRANSFER, and the foreign amount and currency are set (like they seem to be)
            // the correct fields to update in the destination transaction are NOT the foreign amount and currency
            // but rather the normal amount and currency. This is new behavior.
            $isTransfer                  = TransactionTypeEnum::TRANSFER->value === $this->transactionJournal->transactionType->type;
            // also check if it is not between an asset account and a liability, because then the same rule applies.
            $isBetween                   = $this->isBetweenAssetAndLiability();

            if ($isTransfer || $isBetween) {
                Log::debug('Switch amounts, store in amount and not foreign_amount');
                $dest->transaction_currency_id = $foreignCurrency->id;
                $dest->amount                  = app('steam')->positive($foreignAmount);
                $dest->foreign_amount          = app('steam')->positive($source->amount);
                $dest->foreign_currency_id     = $source->transaction_currency_id;
            }
            if (!$isTransfer && !$isBetween) {
                $dest->foreign_currency_id = $foreignCurrency->id;
                $dest->foreign_amount      = app('steam')->positive($foreignAmount);
            }

            $dest->save();

            Log::debug(
                sprintf(
                    'Update foreign info to %s (#%d) %s',
                    $foreignCurrency->code,
                    $foreignCurrency->id,
                    $foreignAmount
                )
            );

            // refresh transactions.
            $this->sourceTransaction->refresh();
            $this->destinationTransaction->refresh();

            return;
        }
        if ('0' === $amount) {
            $source->foreign_currency_id = null;
            $source->foreign_amount      = null;
            $source->save();

            $dest->foreign_currency_id   = null;
            $dest->foreign_amount        = null;
            $dest->save();
            Log::debug(sprintf('Foreign amount is "%s" so remove foreign amount info.', $amount));
        }
        Log::info('Not enough info to update foreign currency info.');

        // refresh transactions.
        $this->sourceTransaction->refresh();
        $this->destinationTransaction->refresh();
    }

    private function isBetweenAssetAndLiability(): bool
    {
        /** @var Transaction $sourceTransaction */
        $sourceTransaction      = $this->transactionJournal->transactions()->where('amount', '<', 0)->first();

        /** @var Transaction $destinationTransaction */
        $destinationTransaction = $this->transactionJournal->transactions()->where('amount', '>', 0)->first();
        if (null === $sourceTransaction || null === $destinationTransaction) {
            Log::warning('Either transaction is false, stop.');

            return false;
        }
        if (null === $sourceTransaction->foreign_amount || null === $destinationTransaction->foreign_amount) {
            Log::warning('Either foreign amount is false, stop.');

            return false;
        }

        $source                 = $sourceTransaction->account;
        $destination            = $destinationTransaction->account;

        if (null === $source || null === $destination) {
            Log::warning('Either is false, stop.');

            return false;
        }
        $sourceTypes            = [AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value];

        // source is liability, destination is asset
        if (in_array($source->accountType->type, $sourceTypes, true) && AccountTypeEnum::ASSET->value === $destination->accountType->type) {
            Log::debug('Source is a liability account, destination is an asset account, return TRUE.');

            return true;
        }
        // source is asset, destination is liability
        if (in_array($destination->accountType->type, $sourceTypes, true) && AccountTypeEnum::ASSET->value === $source->accountType->type) {
            Log::debug('Destination is a liability account, source is an asset account, return TRUE.');

            return true;
        }

        return false;
    }

    public function isCompareHashChanged(): bool
    {
        Log::debug(sprintf('Now in %s', __METHOD__));
        $compareHash = hash('sha256', sprintf('%s', Carbon::now()->getTimestamp()));
        if (!$this->transactionGroup instanceof TransactionGroup) {
            $compareHash = $this->transactionGroupRepository->getCompareHash($this->transactionJournal->transactionGroup);
        }
        if ($this->transactionGroup instanceof TransactionGroup) {
            $this->transactionGroupRepository->getCompareHash($this->transactionGroup);
        }
        Log::debug(sprintf('Compare hash is       "%s".', $compareHash));
        Log::debug(sprintf('Start compare hash is "%s".', $this->startCompareHash));

        return $compareHash !== $this->startCompareHash;
    }
}
