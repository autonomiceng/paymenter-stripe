<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use Paymenter\Extensions\Gateways\AeStripe\ExtensionLocks;

use App\Exceptions\DisplayException;
use App\Models\{BillingAgreement, Credit, Invoice, InvoiceTransaction, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Gateways\AeStripe\{PaymentDeclinedException, PaymentPendingException};

trait CheckoutIntent
{
    private bool $paymentStripeUnavailable = false;
    private bool $paymentRetryReserved = false;
    private function checkoutIntent($invoice, array $data, bool $elements = false)
    {
        for ($round = 0; $round < 4; ++$round) {
            $attempt = $this->reservePaymentAttempt($invoice, $data, $elements);
            foreach (['renewal', 'automatic_renewal', 'customer_payment', 'accepted_amount', 'accepted_currency', 'auto_pay_agreement'] as $key) {
                if (array_key_exists($key, $attempt)) $data[$key] = $attempt[$key];
            }
            if (!empty($attempt['agreement_id'])) $data['agreement_id'] ??= $attempt['agreement_id'];
            $creating = false;
            try {
                if (isset($attempt['intent'])) $intent = $this->request('get', '/payment_intents/' . $attempt['intent']);
                elseif (($attempt['version'] ?? 1) === 2 || $this->legacyUnconfirmedAttempt($invoice, $attempt, array_replace($data, ['amount' => $attempt['due_amount']]))) {
                    // Creation never confirms. After key expiry an orphan cannot have charged.
                    if (time() - $attempt['created'] >= 23 * 3600) {
                        if (!empty($attempt['automatic_renewal'])) {
                            $this->withPaymentLock($invoice, function ($user, $current) use ($attempt) {
                                $stored = $this->readPaymentAttempt($user, $current);
                                if (($stored['key'] ?? null) !== $attempt['key'] || isset($stored['intent'])) return;
                                // Rotate the proven-unconfirmed creation atomically; a crash cannot discard its authority/count.
                                $next = $this->newPaymentAttempt($current, $stored['params'], $this->paymentBalance($current), !empty($stored['elements']));
                                $this->writePaymentAttempt($user, $current, array_replace($stored, $next));
                            });
                        } else $this->replacePaymentAttempt($invoice, $attempt);
                        continue;
                    }
                    $creating = true;
                    $intent = $this->request('post', '/payment_intents', $attempt['params'], $attempt['key']);
                } else {
                    $intent = $this->findLegacyPayment($invoice, $attempt);
                    if (!$intent) $this->holdPaymentAttempt($invoice, $attempt, 'Stripe has no payment ID for this older attempt. Check its request log before releasing it.');
                }
            } catch (RequestException | ConnectionException $e) {
                if ($e instanceof RequestException && $this->definitePaymentRefusal($e) && $creating && !in_array($e->response->status(), [401, 403], true)) {
                    $this->storePaymentAttempt($invoice, $attempt, [
                        'notice_only' => true, 'retry' => true, 'failure_reason' => $this->paymentRefusalReason($e, $invoice),
                        'agreement_id' => null, 'confirmation' => null,
                    ]);
                    throw new DisplayException($this->paymentRefusalReason($e, $invoice));
                }
                if ($e instanceof RequestException && $this->definitePaymentRefusal($e) && $e->response->status() !== 404) {
                    $this->storePaymentAttempt($invoice, $attempt, ['retry' => true]);
                    $this->paymentStripeUnavailable = true;
                    if (($attempt['checks'] ?? 0) >= 2) $this->notifyPaymentAccessRefusal();
                    $this->paymentPending($this->paymentRefusalReason($e, $invoice));
                }
                $this->paymentStripeUnavailable = true;
                if ($e instanceof RequestException && $e->response->status() === 404 && isset($attempt['intent'])) {
                    $this->paymentStripeUnavailable = false;
                    $this->holdPaymentAttempt($invoice, $attempt, $this->missingPaymentReason($attempt));
                }
                $this->storePaymentAttempt($invoice, $attempt, ['retry' => true]);
                $this->paymentPending('Stripe is unavailable. We will check this payment automatically.');
            }
            if (empty($intent->id) || isset($attempt['intent']) && $intent->id !== $attempt['intent']) {
                $this->holdPaymentAttempt($invoice, $attempt, 'Stripe returned a different payment ID. Link the verified payment before continuing.');
            }
            if (!empty($attempt['action'])) {
                try { $this->sendPaymentAction($invoice, $intent, $attempt['action']['kind']); }
                catch (RequestException | ConnectionException $e) {
                    $this->paymentStripeUnavailable = true;
                    $this->paymentPending('We are checking the earlier payment before replacing it.');
                }
                continue;
            }
            if (!empty($attempt['automatic_renewal']) && ($intent->amount_received ?? 0) === 0
                && $this->paymentAttemptFailed($attempt, $intent)) {
                try { $fresh = $this->request('get', '/payment_intents/' . $intent->id); }
                    catch (RequestException | ConnectionException $e) {
                        $this->paymentStripeUnavailable = true;
                        $this->paymentPending('We are checking this payment automatically. Refresh the invoice for its status.');
                    }
                $refused = $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $intent, $fresh) {
                    $stored = $this->readPaymentAttempt($user, $current);
                    if (($stored['key'] ?? null) !== $attempt['key'] || empty($stored['automatic_renewal'])) return false;
                    if (($fresh->amount_received ?? 0) !== 0 || !$this->paymentAttemptFailed($stored, $fresh)) return false;
                    $this->keepPreviousFailedAttempt($stored, $fresh);
                    // Keep the claimed confirmation until refusal and renewal policy commit together.
                    $this->recordIntentPayment($fresh, 'failed');
                    return $fresh;
                });
                if ($refused) return $refused; // The committed policy prevents this automatic caller from confirming.
                continue; // The attempt or provider result changed; inspect the current reservation again.
            }
            // A concurrent page load may have retired this unconfirmed native intent for Elements.
            if (!$this->rememberPaymentIntent($invoice, $attempt, $intent)) continue;
            if ($intent->status === 'succeeded') {
                $recorded = InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists();
                if (!$recorded) return $intent;
                // A completed charge is not a new payment of a later balance.
                $this->replacePaymentAttempt($invoice, $attempt);
                continue;
            }
            if (in_array($intent->status, ['processing', 'requires_capture'], true)) {
                $this->storePaymentAttempt($invoice, $attempt, ['retry' => true, 'agreement_id' => null, 'confirmation' => null]);
                return $intent;
            }
            if (($intent->amount_received ?? 0) > 0) {
                $this->holdPaymentAttempt($invoice, $attempt, 'Stripe received part of this payment. Check the funds before replacing it.');
            }
            if (in_array($intent->status, ['requires_payment_method', 'canceled'], true)) {
                foreach ($invoice->transactions()->where('status', 'processing')->get() as $transaction) {
                    if ($transaction->transaction_id === $intent->id) { $this->recordIntentPayment($intent, 'failed'); break; }
                }
            }
            if ($intent->status === 'canceled') {
                // A new checkout can take over only after Stripe proves the earlier payment canceled without funds.
                if ($elements) unset($data['agreement_id'], $data['accepted_amount'], $data['accepted_currency'], $data['auto_pay_agreement']);
                $this->replacePaymentAttempt($invoice, $attempt);
                continue;
            }
            // Recovery reports refusal; only explicit checkout can retire the old debit instruction.
            if (!$elements && $this->paymentAttemptFailed($attempt, $intent)) return $intent;
            $usesElements = $this->isElementsAttempt($attempt, $intent);
            $legacyAutomatic = ($intent->confirmation_method ?? 'automatic') !== 'manual' && !$usesElements;
            $changeFlow = $usesElements && !$elements && isset($data['agreement_id'])
                || $elements && !$usesElements && $intent->status === 'requires_payment_method';
            $revokedAutoPay = $elements && isset($attempt['auto_pay_agreement'])
                && (int) ($this->autoPayDefault($invoice->user)['agreement_id'] ?? 0) !== $attempt['auto_pay_agreement'];
            $mismatchedElements = $elements && $usesElements && (!isset($intent->payment_method_types)
                || !self::sameMethodTypes($intent->payment_method_types, ['card', 'link']));
            $failedElements = $usesElements && $intent->status === 'requires_payment_method' && !empty($intent->last_payment_error);
            if ($legacyAutomatic || $changeFlow || $failedElements || $revokedAutoPay || $mismatchedElements
                || strtolower($intent->currency) !== strtolower($invoice->currency_code)
                || $intent->customer !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value) {
                // Retire legacy tabs, failed Elements authentication and changes between native Pay and Elements.
                try {
                    $fresh = $this->request('get', '/payment_intents/' . $intent->id);
                    $this->sendPaymentAction($invoice, $fresh, 'cancel');
                }
                catch (RequestException | ConnectionException $e) {
                    $this->paymentStripeUnavailable = true;
                    $this->storePaymentAttempt($invoice, $attempt, ['retry' => true]);
                    $this->paymentPending('We are checking the earlier payment before replacing it.');
                }
                // Read Stripe again: only a proven cancellation permits replacement.
                continue;
            }
            try {
                if (isset($intent->amount) && $intent->amount !== $attempt['due_amount']) {
                    $fresh = $this->request('get', '/payment_intents/' . $intent->id);
                    $intent = $this->sendPaymentAction($invoice, $fresh, 'amount');
                    if ($intent->status === 'canceled') continue;
                }
            } catch (RequestException | ConnectionException $e) {
                if ($e instanceof RequestException && $this->definitePaymentRefusal($e)) {
                    $this->storePaymentAttempt($invoice, $attempt, ['retry' => false, 'agreement_id' => null, 'confirmation' => null]);
                    throw new DisplayException($this->paymentRefusalReason($e, $invoice));
                }
                $this->storePaymentAttempt($invoice, $attempt, ['retry' => true]);
                $this->paymentStripeUnavailable = true;
                $this->paymentPending('Stripe is unavailable. We will check this payment automatically.');
            }
            return $intent;
        }
        $this->paymentPending('Payment changed while loading. Refresh the invoice.');
    }

    private function reservePaymentAttempt($invoice, array $data, bool $elements = false): array
    {
        $this->paymentRetryReserved = false;
        $domains = !empty($data['renewal_start']) && $this->retainedRenewalInvoice($invoice)
            ? $this->domainsRenewalContext($invoice) : null;
        $attempt = $this->withPaymentLock($invoice, function ($user, $current) use ($data, $elements, $domains) {
            $amount = $this->paymentBalance($current);
            if ($current->status !== 'pending' || $amount <= 0) throw new DisplayException('This invoice has no payment due. Refresh the invoice.');
            $attempt = $this->readPaymentAttempt($user, $current);
            $customerPay = $elements || array_key_exists('renewal_start', $data) && !$data['renewal_start'];
            $context = array_intersect_key($attempt ?? $data, array_flip(['renewal', 'automatic_renewal', 'customer_payment', 'accepted_amount', 'accepted_currency', 'auto_pay_agreement']));
            foreach (['accepted_amount', 'accepted_currency', 'auto_pay_agreement'] as $key) {
                if (array_key_exists($key, $data)) $context[$key] = $data[$key];
            }
            // Older accepted quotes already froze their currency in the creation parameters.
            if (isset($context['accepted_amount'], $attempt['params']['currency'])) {
                $context['accepted_currency'] ??= strtolower($attempt['params']['currency']);
            }
            if (!($elements && $attempt) && isset($context['accepted_currency']) && strtolower($current->currency_code) !== $context['accepted_currency']) {
                throw new DisplayException('The amount due changed. Refresh the invoice before paying.');
            }
            if (!($elements && $attempt) && isset($context['auto_pay_agreement']) && empty($attempt['confirmation']) && empty($attempt['funds_pending'])
                && (int) ($this->autoPayDefault($user, true)['agreement_id'] ?? 0) !== $context['auto_pay_agreement']) {
                throw new DisplayException('The Auto Pay card changed. Refresh Payment methods before paying.');
            }
            if (!empty($attempt['renewal_only'])) {
                if (!$customerPay) throw new DisplayException('This renewal is waiting for its next automatic retry. Open the invoice to pay now.');
                $attempt = null;
            }
            if (!empty($attempt['notice_only'])) $attempt = null; // A new Pay starts fresh; this marker can only notify.
            if (!$attempt) {
                // Earlier bank debits have a transaction ID but no attempt property.
                $processing = $this->lockedTransactions($current->transactions(), 'processing');
                foreach ($processing as $transaction) {
                    if (($transaction->gateway->extension ?? null) === 'AeStripe') {
                        $attempt = ['key' => 'adopt_' . $transaction->transaction_id, 'created' => time(), 'intent' => $transaction->transaction_id];
                        break;
                    }
                }
            }
            // R3 could reserve an invalid pair, but its creation never confirmed.
            if (($attempt['version'] ?? null) === 2 && empty($attempt['intent'])
                && empty($attempt['confirmation']) && empty($attempt['params']['confirm'])
                && isset($attempt['params']['automatic_payment_methods'], $attempt['params']['confirmation_method'])) {
                if (!isset($data['agreement_id']) && !empty($attempt['agreement_id'])) $data['agreement_id'] = $attempt['agreement_id'];
                $attempt = null;
            }
            if (!$attempt) {
                $attempt = $this->newPaymentAttempt($current, array_replace($data, [
                    'customer' => $user->properties()->where('key', 'stripe_id')->firstOrFail()->value,
                ]), $amount, $elements);
                $attempt = array_replace($attempt, $context);
                if (!empty($data['renewal_start']) && empty($attempt['customer_payment'])
                    && !isset($attempt['automatic_renewal']) && ($this->nativeRenewalServices($current) || $this->retainedRenewalInvoice($current))) {
                    $attempt['automatic_renewal'] = true;
                    $renewal = $this->renewalEnrollment($current, (int) $data['agreement_id'], $domains);
                    if ($renewal) $attempt['renewal'] = $renewal;
                }
            }
            if ($customerPay) {
                unset($attempt['renewal'], $attempt['automatic_renewal'], $attempt['failure_reason'], $attempt['failure_notified']);
                $attempt['customer_payment'] = true; // Only an explicit customer takeover removes automatic authority.
            } elseif (!empty($data['renewal_start']) && empty($attempt['customer_payment']) && ($this->nativeRenewalServices($current) || $this->retainedRenewalInvoice($current))) {
                $attempt['automatic_renewal'] = true;
            }
            if (isset($data['agreement_id']) && (empty($attempt['automatic_renewal'])
                || ($attempt['renewal']['state'] ?? null) === 'active' && empty($attempt['confirmation']))) {
                $attempt['agreement_id'] = $data['agreement_id'];
                $attempt['agreement_pending'] = true;
                $attempt['retry'] = true;
            }
            $attempt = array_replace($attempt, array_intersect_key($context, array_flip(['accepted_amount', 'accepted_currency', 'auto_pay_agreement'])));
            $this->writePaymentAttempt($user, $current, $attempt);
            return $attempt + ['due_amount' => $amount];
        });
        $this->paymentRetryReserved = !empty($attempt['retry']);
        return $attempt;
    }

    private function rememberPaymentIntent($invoice, array $attempt, $intent): bool
    {
        $values = [
            'intent' => $intent->id,
            'latest_charge' => $intent->latest_charge ?? null,
            'funds_pending' => ($intent->amount_received ?? 0) > 0 || in_array($intent->status, ['succeeded', 'processing', 'requires_capture'], true),
            'retry' => !empty($attempt['confirmation']) || !empty($attempt['agreement_id']) || in_array($intent->status, ['succeeded', 'processing', 'requires_capture'], true),
        ];
        // An agreement is an interrupted instruction, never permission to repeat a debit.
        if (in_array($intent->status, ['succeeded', 'processing', 'requires_capture', 'requires_action'], true)) $values['agreement_id'] = null;
        if ($this->paymentAttemptFailed($attempt, $intent)) {
            $values += ['agreement_id' => null, 'confirmation' => null];
            $values['retry'] = false;
        }
        return $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $intent, $values) {
            $stored = $this->readPaymentAttempt($user, $current);
            if (($stored['key'] ?? null) !== $attempt['key']) return false;
            $refused = !empty($stored['confirmation']) && $this->paymentAttemptFailed($stored, $intent);
            if ($refused) $this->keepPreviousFailedAttempt($stored, $intent);
            $this->writePaymentAttempt($user, $current, array_replace($stored, $values));
            // The earlier refusal, cleared confirmation and new outcome commit together.
            if ($refused) $this->recordIntentPayment($intent, 'failed');
            return true;
        });
    }

    private function keepPreviousFailedAttempt(array $attempt, $intent): void
    {
        // A rolled-back confirmation can still have its earlier refusal under the intent ID.
        if (!empty($attempt['confirmation']['previous_charge'])
            && $attempt['confirmation']['previous_charge'] !== ($intent->latest_charge ?? null)) {
            $this->keepFailedAttempt((object) ['id' => $intent->id, 'latest_charge' => $attempt['confirmation']['previous_charge']]);
        }
    }

    /**
     * Native payments are keyed by transaction ID, so confirming a refused intent again would overwrite its Failed row
     * with the new outcome. Re-key the earlier refusal by its charge first, so every attempt stays on the invoice.
     */
    private function keepFailedAttempt($intent): void
    {
        $charge = is_string($intent->latest_charge ?? null) && $intent->latest_charge !== '' ? $intent->latest_charge : 'failed_' . bin2hex(random_bytes(6));
        // By primary key: an update by transaction_id would lock every row and gap of invoice_transactions.
        $failed = array_map(fn ($row) => $row->id, $this->lockedTransactions(InvoiceTransaction::where('transaction_id', $intent->id), 'failed'));
        if ($failed) InvoiceTransaction::whereKey($failed)->update(['transaction_id' => $intent->id . ':' . $charge]);
    }

    private function failedPaymentId($intent, array $attempt): string
    {
        $charge = is_object($intent->latest_charge ?? null) ? ($intent->latest_charge->id ?? null) : ($intent->latest_charge ?? null);
        $source = $charge ?: ($attempt['refusal_key'] ?? $attempt['confirmation']['key'] ?? $attempt['key'] ?? null);
        return $source ? $intent->id . ':' . $source : $intent->id;
    }

    private function hasFailedPayment($invoice, string $id): bool
    {
        if ($invoice) {
            foreach ($invoice->transactions()->where('status', 'failed')->get() as $row) {
                if ($row->transaction_id === $id || str_starts_with($row->transaction_id, $id . ':')) return true;
            }
            return false;
        }
        $prefix = addcslashes($id, '%_\\') . ':%';
        return InvoiceTransaction::where('transaction_id', $id)->where('status', 'failed')->exists()
            || InvoiceTransaction::where('transaction_id', 'like', $prefix)->where('status', 'failed')->exists();
    }

    private function paymentAttemptFailed(array $attempt, $intent): bool
    {
        if ($intent->status !== 'requires_payment_method') return false;
        // A new explicit Pay is still unsent; an earlier decline cannot cancel that instruction.
        if (empty($attempt['confirmation']) && !empty($attempt['agreement_id']) && !empty($attempt['agreement_pending'])) return false;
        if (!empty($attempt['confirmation']) && array_key_exists('previous_charge', $attempt['confirmation'])) {
            // A previous failed row/error says nothing about this later unknown confirm.
            if (empty($intent->latest_charge) || $intent->latest_charge === $attempt['confirmation']['previous_charge']) return false;
        }
        // Older confirmations have no baseline: honor a known failure rather than risk repeating a failed debit.
        return !empty($intent->last_payment_error)
            || $this->hasFailedPayment(null, $intent->id);
    }

    private function newPaymentAttempt($invoice, array $data, int $amount, bool $elements = false): array
    {
        $params = $this->checkoutPaymentData($invoice, $amount, $data['customer']);
        // Stripe rejects automatic_payment_methods together with confirmation_method.
        // Keep checkoutPaymentData unchanged for legacy digest reconstruction.
        unset($params['automatic_payment_methods']);
        if ($elements) $params['payment_method_types'] = ['card', 'link'];
        else $params['confirmation_method'] = 'manual';
        $key = 'checkout_' . bin2hex(random_bytes(16));
        $params['metadata']['ae_stripe_attempt'] = $key;
        return ['version' => 2, 'key' => $key, 'created' => time(), 'params' => $params, 'elements' => $elements];
    }

    private function isElementsAttempt(array $attempt, $intent): bool
    {
        return !empty($attempt['elements']) && ($attempt['intent'] ?? $intent->id) === $intent->id
            && ($intent->confirmation_method ?? null) === 'automatic';
    }

    private function retireFailedElementsPayment($invoice, $intent)
    {
        if (($intent->status ?? null) !== 'requires_payment_method' || empty($intent->last_payment_error)) return $intent;
        $elements = $this->withPaymentLock($invoice, fn ($user, $current) => $this->isElementsAttempt($this->readPaymentAttempt($user, $current) ?? [], $intent));
        if (!$elements) return $intent;
        $fresh = $this->request('get', '/payment_intents/' . $intent->id);
        return $fresh->status === 'requires_payment_method' && !empty($fresh->last_payment_error)
            ? $this->sendPaymentAction($invoice, $fresh, 'cancel') : $fresh;
    }

    /**
     * Runs $callback under the customer's extension scope and the invoice's exact primary key, and runs it again when MariaDB
     * rolls it back as a deadlock victim. With $rerun, a rolled back transaction runs again only while $rerun() is
     * true: a callback that sends a Stripe write makes it false first, so that write is never sent twice.
     */
    private function withPaymentLock($invoice, callable $callback, ?callable $rerun = null)
    {
        $locked = function () use ($invoice, $callback) {
            $user = User::where('id', $invoice->user_id)->firstOrFail();
            $current = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $user->id, 403);
            return $callback($user, $current);
        };
        if (!$rerun) return ExtensionLocks::transaction('user:' . $invoice->user_id, $locked, self::SETTLEMENT_ATTEMPTS);
        for ($attempt = 1; ; ++$attempt) {
            try { return ExtensionLocks::transaction('user:' . $invoice->user_id, $locked, 1); }
            catch (\Throwable $e) {
                if ($attempt >= self::SETTLEMENT_ATTEMPTS || DB::transactionLevel() > 0 || !$rerun() || !$this->lockConflict($e)) throw $e;
            }
        }
    }

    /** A deadlock or lock wait timeout, after which MariaDB has rolled the work back and it can run again. */
    private function lockConflict(\Throwable $e): bool
    {
        return $e instanceof \Illuminate\Database\DeadlockException || $e instanceof \Illuminate\Database\QueryException
            && preg_match('/SQLSTATE\[40001\]|Deadlock found when trying to get lock|Lock wait timeout exceeded/', $e->getMessage());
    }

    private function paymentBalance($invoice): int
    {
        $paid = array_sum(array_map(fn ($row) => $row->amount, $this->lockedTransactions($invoice->transactions(), 'succeeded')));
        return $this->toMinorUnits($invoice->total - $paid, $invoice->currency_code);
    }

    private function readPaymentAttempt($user, $invoice): ?array
    {
        $stored = $this->lockedUserProperty($user, 'ae_stripe_checkout_' . $invoice->id)?->value;
        return $stored ? $this->clearFinishedPaymentConstraints(json_decode($stored, true, flags: JSON_THROW_ON_ERROR)) : null;
    }

    /** Read extension properties under the customer's extension scope, without locking shared index gaps. */
    private function lockedUserProperty($user, string $key)
    {
        return $user->properties()->where('key', $key)->first();
    }

    /** Read and sort the customer's extension properties without a locking range scan. */
    private function lockedUserProperties($user, string $prefix): array
    {
        $rows = [];
        foreach ($user->properties()->where('key', 'like', $prefix . '%')->get() as $row) {
            if ($locked = $user->properties()->where('key', $row->key)->first()) $rows[] = $locked;
        }
        return $rows;
    }

    /**
     * Invoice transactions matching the query, with the given status, read under the payment locks. Paymenter writes a
     * transaction while holding the invoice row lock (ExtensionHelper::addPayment) and this extension also holds the
     * owner's user row lock, so a plain read sees the latest rows. Only the rows found are then locked, by primary key, in
     * id order. invoice_transactions has no transaction_id index, so a locking read by transaction_id locks every row
     * and gap of the table, and a locking read by invoice locks the invoice_id index gap shared with the next invoice.
     * The payment inserted next waits on either, so two settlements, or a Pay and a settlement, deadlock.
     */
    private function lockedTransactions($query, ?string $status = null): array
    {
        $ids = ($status === null ? $query : $query->where('status', $status))->pluck('id')->all();
        $locked = [];
        sort($ids);
        foreach ($ids as $id) {
            $row = InvoiceTransaction::whereKey($id)->lockForUpdate()->first();
            if (!$row) continue;
            if ($status === null || ($row->status->value ?? $row->status) === $status) $locked[] = $row;
        }
        return $locked;
    }

    /**
     * The owner's account credit in a currency, read under the user row lock that every credit change here holds, and
     * then locked by primary key. Null when there is none; the caller inserts it without a locking read first. A locking
     * read of a missing credit locks the credits user_id index gap shared with other customers, and two customers who
     * each lock that gap and then insert their credit deadlock.
     */
    private function lockedCredit($user, string $currency): ?Credit
    {
        $id = Credit::where('user_id', $user->id)->where('currency_code', $currency)->orderBy('id')->value('id');
        return $id ? Credit::whereKey($id)->lockForUpdate()->first() : null;
    }

    private function clearFinishedPaymentConstraints(array $attempt): array
    {
        // An ended instruction cannot constrain a later Pay. Unknown confirmations and funds keep their constraints.
        if (empty($attempt['agreement_id']) && empty($attempt['confirmation']) && empty($attempt['funds_pending']) && empty($attempt['state'])) {
            unset($attempt['accepted_amount'], $attempt['accepted_currency'], $attempt['auto_pay_agreement']);
        }
        return $attempt;
    }

    private function writePaymentAttempt($user, $invoice, array $attempt): void
    {
        $attempt = $this->clearFinishedPaymentConstraints($attempt);
        if (empty($attempt['agreement_id'])) unset($attempt['agreement_pending']);
        $user->properties()->updateOrCreate(['key' => 'ae_stripe_checkout_' . $invoice->id], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
    }

    private function replacePaymentAttempt($invoice, array $attempt, bool $keepWaitingRenewal = false): void
    {
        $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $keepWaitingRenewal) {
            $stored = $this->readPaymentAttempt($user, $current);
            if (($stored['key'] ?? null) === $attempt['key']) {
                if ($keepWaitingRenewal && ($stored['renewal']['state'] ?? null) === 'waiting'
                    && $current->status === 'pending' && $this->paymentBalance($current) > 0) {
                    $schedule = array_intersect_key($stored, array_flip(['version', 'key', 'created', 'renewal',
                        'automatic_renewal', 'failure_reason', 'failure_notified']));
                    $this->writePaymentAttempt($user, $current, $schedule + ['renewal_only' => true, 'retry' => false]);
                    return;
                }
                $user->properties()->where('key', 'ae_stripe_checkout_' . $current->id)->delete();
            }
        });
    }

    private function storePaymentAttempt($invoice, array $attempt, array $values, bool $confirmationOnly = false): bool
    {
        return $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $values, $confirmationOnly) {
            $stored = $this->readPaymentAttempt($user, $current);
            if ($confirmationOnly && ($stored['confirmation']['key'] ?? null) !== ($attempt['confirmation']['key'] ?? null)) return false;
            if (($stored['key'] ?? null) !== $attempt['key']) return false;
            $this->writePaymentAttempt($user, $current, array_replace($stored, $values));
            return true;
        });
    }

    private function legacyUnconfirmedAttempt($invoice, array &$attempt, array $data): bool
    {
        if (isset($attempt['params'])) return empty($attempt['params']['confirm']);
        $params = $this->checkoutPaymentData($invoice, $data['amount'], $data['customer']);
        $legacy = $params;
        $legacy['metadata'] = ['invoice_id' => $invoice->id, 'stripe_saved_gateway' => '1'];
        foreach ([$params, $legacy] as $candidate) {
            if (($attempt['digest'] ?? null) === hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR))) {
                $attempt['params'] = $candidate;
                $this->storePaymentAttempt($invoice, $attempt, ['params' => $candidate]);
                return true;
            }
        }
        return false;
    }

    private function confirmAttempt($invoice, string $intentId, array $params, ?BillingAgreement $agreement = null, ?int $expectedAmount = null, ?string $expectedCurrency = null)
    {
        // Commit the confirmation identity before HTTP. Other extension paths honor this claim until its answer is recorded.
        $attempt = $this->withPaymentLock($invoice, function ($user, $current) use ($intentId, $params, $agreement, $expectedAmount, $expectedCurrency) {
            $attempt = $this->readPaymentAttempt($user, $current);
            if (($attempt['intent'] ?? null) !== $intentId) $this->paymentPending('Payment changed. Refresh the invoice to check its status.');
            $expectedAmount = $attempt['accepted_amount'] ?? $expectedAmount;
            $expectedCurrency = $attempt['accepted_currency'] ?? $expectedCurrency
                ?? $attempt['confirmation']['currency'] ?? $attempt['params']['currency'] ?? null;
            if (!empty($attempt['automatic_renewal'])) {
                if (($attempt['renewal']['state'] ?? null) !== 'active') throw new DisplayException('Auto Pay stopped for this renewal. Open the invoice to pay the amount due.');
                if (!empty($attempt['confirmation'])) return $attempt; // Resume the claimed instruction, never replace its identity.
            }
            if (!empty($attempt['confirmation']) && ($attempt['confirmation']['phase'] ?? null) === 'sending') return $attempt;
            $attempt['confirmation'] = ['key' => 'confirm_' . bin2hex(random_bytes(16)), 'params' => $params, 'agreement' => $agreement?->id, 'amount' => $expectedAmount, 'currency' => $expectedCurrency, 'previous_charge' => $attempt['latest_charge'] ?? null, 'phase' => 'reserved'];
            // Keep this identity after the confirmation is cleared; another Pay can reuse the checkout attempt.
            $attempt['refusal_key'] = $attempt['confirmation']['key'];
            unset($attempt['agreement_pending']); // The confirmation now owns this instruction.
            $attempt['retry'] = true;
            $attempt['failure_reason'] = null;
            $attempt['failure_notified'] = false;
            if ($expectedAmount !== null) $attempt['checkout_amount'] = $expectedAmount;
            if (isset($params['confirmation_token'])) $attempt['agreement_id'] = null;
            $this->writePaymentAttempt($user, $current, $attempt);
            return $attempt;
        });
        $expectedAmount = $attempt['accepted_amount'] ?? $expectedAmount;
        $expectedCurrency = $attempt['confirmation']['currency'] ?? $attempt['params']['currency'] ?? null;
        if (!empty($attempt['automatic_renewal'])) {
            $params = $attempt['confirmation']['params'];
            $expectedAmount = $attempt['confirmation']['amount'];
        }
        $writeStarted = false;
        $previous = null;
        // Records Stripe's answer: a proven refusal ends this confirmation, and a declined renewal is recorded as failed.
        // The refusal, the failed payment and the renewal policy commit in one owner and invoice transaction, or not at all.
        $record = function ($outcome, bool $oneShot = false) use ($invoice, $attempt, &$previous) {
            return $this->withPaymentLock($invoice, function () use ($outcome, $invoice, $attempt, $previous) {
                // Repeat the idempotent re-key if the confirmation transaction rolled back.
                if ($previous) $this->keepFailedAttempt($previous);
                if (is_array($outcome)) {
                    [$fresh, $e] = [$outcome['refused'], $outcome['error']];
                    $this->storePaymentAttempt($invoice, $attempt, ['retry' => false, 'confirmation' => null, 'agreement_id' => null], confirmationOnly: true);
                    $declined = $fresh->status === 'requires_payment_method' && ($e->response->status() === 402 || !empty($fresh->last_payment_error));
                    if ($declined) $this->recordIntentPayment($fresh, 'failed');
                    $reason = $this->paymentRefusalReason($e, $invoice, $fresh->last_payment_error->code ?? null, $fresh->last_payment_error->decline_code ?? null);
                    return $declined ? new PaymentDeclinedException($reason) : new DisplayException($reason);
                }
                if (!empty($attempt['automatic_renewal']) && $outcome->status === 'requires_payment_method'
                    && ($outcome->amount_received ?? 0) === 0 && !empty($outcome->last_payment_error)) $this->recordIntentPayment($outcome, 'failed');
                return $outcome;
            }, $oneShot ? fn () => false : null);
        };
        $outcome = null;
        $result = null;
        try {
            $intent = $this->request('get', '/payment_intents/' . $intentId);
            $method = $agreement && in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)
                ? $this->request('get', '/payment_methods/' . $agreement->external_reference) : null;
            $previous = clone $intent;
            if (array_key_exists('previous_charge', $attempt['confirmation'])) $previous->latest_charge = $attempt['confirmation']['previous_charge'];
            $send = $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $intent, $params, $agreement, $method, $expectedAmount, $expectedCurrency) {
                $stored = $this->readPaymentAttempt($user, $current);
                if (($stored['confirmation']['key'] ?? null) !== $attempt['confirmation']['key']) return null;
                if (!empty($stored['action'])) return null;
                if (!in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)) return $intent;
                if (isset($stored['confirmation']['updates'])) return $stored['confirmation'];
                if (!empty($stored['automatic_renewal']) && (empty($stored['renewal'])
                    || $stored['renewal']['state'] !== 'active' || !$this->renewalStillAuthorized($current, $stored['renewal']))) {
                    return new DisplayException('Auto Pay stopped for this renewal. Open the invoice to pay the amount due.');
                }
                if (isset($stored['auto_pay_agreement'])
                    && (int) ($this->autoPayDefault($user, true)['agreement_id'] ?? 0) !== $stored['auto_pay_agreement']) {
                    return new DisplayException('The Auto Pay card changed. Refresh Payment methods before paying.');
                }
                $amount = $this->paymentBalance($current);
                if ($current->status !== 'pending' || $amount <= 0) return new DisplayException('This invoice has no payment due. Refresh the invoice.');
                if ($expectedCurrency !== null && strtolower($current->currency_code) !== strtolower($expectedCurrency)
                    || strtolower($intent->currency) !== strtolower($current->currency_code) || $expectedAmount !== null && $amount !== $expectedAmount) {
                    return new DisplayException('The amount due changed. Refresh the invoice before paying.');
                }
                if ($agreement) {
                    if (($stored['agreement_id'] ?? $agreement->id) != $agreement->id) return new DisplayException('The payment method changed. Choose your payment method again.');
                    $active = BillingAgreement::where('id', $agreement->id)->lockForUpdate()->first();
                    if (!$active || $active->user_id != $user->id || ($active->gateway->extension ?? null) !== 'AeStripe'
                        || $active->external_reference !== $params['payment_method']) {
                        return new DisplayException('This payment method was removed. Choose another payment method.');
                    }
                    if (($method->customer ?? null) !== $intent->customer) return new DisplayException('This payment method is no longer saved. Choose another payment method.');
                }
                if ($intent->customer !== $user->properties()->where('key', 'stripe_id')->first()?->value) return new DisplayException('The payment account changed. Refresh the invoice before paying.');
                if (($intent->confirmation_method ?? null) !== 'manual' && !$this->isElementsAttempt($stored, $intent)) return new DisplayException('The payment form changed. Refresh the invoice before paying.');
                if (isset($params['confirmation_token']) && (!$this->isElementsAttempt($stored, $intent)
                    || !isset($intent->payment_method_types) || !self::sameMethodTypes($intent->payment_method_types, ['card', 'link']))) return new DisplayException('The payment form changed. Refresh the invoice before paying.');
                $updates = ['amount' => $amount];
                foreach (['metadata', 'setup_future_usage'] as $field) {
                    if (array_key_exists($field, $params)) { $updates[$field] = $params[$field]; unset($params[$field]); }
                }
                $stored['confirmation']['updates'] = $updates;
                $stored['confirmation']['params'] = $params;
                $stored['confirmation']['phase'] = 'sending';
                $this->writePaymentAttempt($user, $current, $stored);
                return $stored['confirmation'];
            });
            if (is_array($send)) {
                $writeStarted = true;
                $this->request('post', '/payment_intents/' . $intentId, $send['updates'], $send['key'] . '_update');
                $outcome = $this->request('post', '/payment_intents/' . $intentId . '/confirm', $send['params'], $send['key']);
            } else $result = $send;
        } catch (RequestException | ConnectionException $e) {
            if ($e instanceof RequestException && $writeStarted && $this->definitePaymentRefusal($e)) {
                try {
                    $fresh = $this->request('get', '/payment_intents/' . $intentId);
                    if (($fresh->amount_received ?? 0) === 0 && in_array($fresh->status, ['requires_payment_method', 'requires_confirmation', 'canceled'], true)) {
                        $outcome = ['refused' => $fresh, 'error' => $e];
                    } else $result = $fresh;
                } catch (RequestException | ConnectionException $readError) { $result = $readError; }
            } else $result = $e;
        }
        if ($outcome !== null) {
            try { $result = $record($outcome, true); }
            catch (\Throwable $e) {
                // Retry only local recording; the already-sent provider writes are never rerun.
                if (DB::transactionLevel() > 0 || !$this->lockConflict($e)) throw $e;
                try { $result = $record($outcome); }
                catch (\Throwable $e) {
                    if (DB::transactionLevel() > 0 || !$this->lockConflict($e)) throw $e;
                    report($e);
                    $this->paymentPending('We are checking this payment automatically. Refresh the invoice for its status.');
                }
            }
        }
        if ($result === null || $result instanceof RequestException || $result instanceof ConnectionException) {
            $this->paymentStripeUnavailable = true;
            $this->paymentPending('We are checking this payment automatically. Refresh the invoice for its status.');
        }
        if (!($result instanceof DisplayException) && ($result->amount_received ?? 0) > 0
            && !in_array($result->status, ['succeeded', 'processing', 'requires_capture'], true)) {
            $this->storePaymentAttempt($invoice, $attempt, ['funds_pending' => true, 'agreement_id' => null, 'confirmation' => null], confirmationOnly: true);
            $this->holdPaymentAttempt($invoice, $attempt, 'Stripe received part of this payment. Check the funds before replacing it.');
        }
        $resume = null;
        if (!($result instanceof DisplayException) && $result->status === 'requires_action') {
            // Bank verification may finish days later, without a browser return. The consumed
            // token must not be replayed; Stripe already holds the authenticated payment method.
            $resumeParams = ['return_url' => route('extensions.gateways.ae-stripe.payment-return', ['invoice' => $invoice->id]), 'use_stripe_sdk' => 'true'];
            if ($agreement) $resumeParams['payment_method'] = $agreement->external_reference;
            $resume = ['key' => $attempt['confirmation']['key'], 'params' => $resumeParams, 'agreement' => $agreement?->id, 'amount' => $expectedAmount ?? $result->amount, 'currency' => $expectedCurrency ?? $result->currency, 'phase' => 'observed'];
        }
        $this->storePaymentAttempt($invoice, $attempt, ['retry' => !($result instanceof DisplayException)
            && in_array($result->status, ['succeeded', 'processing', 'requires_capture', 'requires_action'], true), 'confirmation' => $resume, 'agreement_id' => null, 'funds_pending' => !($result instanceof DisplayException)
                && (($result->amount_received ?? 0) > 0 || in_array($result->status, ['succeeded', 'processing', 'requires_capture'], true))], confirmationOnly: true);
        if ($result instanceof DisplayException) throw $result;
        if ($result->status === 'requires_payment_method' && !empty($result->last_payment_error)) {
            $this->recordIntentPayment($result, 'failed');
            throw new PaymentDeclinedException($this->paymentFailureReason($result->last_payment_error->code ?? null, $result->last_payment_error->decline_code ?? null));
        }
        return $result;
    }

    private function definitePaymentRefusal(RequestException $e): bool
    {
        return $e->response->status() >= 400 && $e->response->status() < 500 && !in_array($e->response->status(), [409, 429], true);
    }

    private function paymentRefusalReason(RequestException $e, $invoice, ?string $fallbackCode = null, ?string $fallbackDecline = null): string
    {
        if (str_contains($e->response->json('error.message') ?? '', 'The currency provided')) return "This card can't be charged in " . $invoice->currency_code . '. Use another payment method.';
        if (in_array($e->response->status(), [401, 403], true)) return 'Payments are paused. Please check back later or contact support.';
        return $this->paymentFailureReason($e->response->json('error.code') ?? $fallbackCode, $e->response->json('error.decline_code') ?? $fallbackDecline);
    }

    /** The customer's words for a refusal. The decline code, when Stripe sends one, names the real reason. */
    private function paymentFailureReason(?string $code, ?string $declineCode = null): string
    {
        if ($declineCode === 'insufficient_funds') return 'Insufficient funds. Try a different card, or contact your bank.';
        if ($code === 'payment_intent_authentication_failure' || $declineCode === 'authentication_required') {
            return 'Your bank did not confirm this payment. You were not charged. Try again or use a different card.';
        }
        return match ($code) {
            'amount_too_small' => "This balance is below Stripe's minimum. Use account credit or contact support to pay it.",
            'card_declined' => 'Your bank declined this card. Try a different card, or contact your bank.',
            'expired_card' => 'This card has expired. Choose another payment method.',
            'resource_missing' => 'The saved payment details are no longer available. Choose another payment method.',
            default => 'Stripe refused this payment. Choose another payment method or contact support.',
        };
    }

    private function paymentPending(string $message = 'Payment pending. Refresh the invoice to check its status.'): never
    {
        throw new PaymentPendingException($message);
    }

    private function paymentReconcileMessage(): never
    {
        $this->paymentPending('Payment needs review. Contact support to check this payment.');
    }
}
