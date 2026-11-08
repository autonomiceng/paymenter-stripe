<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, User};
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\{Cache, DB, Log};
use Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException;

trait SavedMethods
{
    private function savedMethodIdentity($method): ?string
    {
        $linkEmail = strtolower(trim((string) ($method->link->email ?? '')));
        $identity = match ($method->type ?? null) {
            'card' => empty($method->card->fingerprint) ? null : $method->card->fingerprint,
            // Only Stripe's Link account email identifies the account. Billing contact email does not.
            'link' => filter_var($linkEmail, FILTER_VALIDATE_EMAIL) ? $linkEmail : null,
            default => null,
        };
        return $identity === null ? null : hash('sha256', $method->type . ':' . $identity);
    }

    /** Read-only identities for retained agreements, scoped to this customer and gateway. */
    public function savedMethodIdentities($user, iterable $agreements): array
    {
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        $identities = [];
        if (!$customer) return $identities;
        foreach ($agreements as $agreement) {
            if ($agreement->user_id != $user->id || $agreement->gateway_id != $gateway->id || $agreement->trashed()) continue;
            try {
                $method = $this->request('get', '/payment_methods/' . $agreement->external_reference);
                if (($method->id ?? null) === $agreement->external_reference && ($method->customer ?? null) === $customer && ($identity = $this->savedMethodIdentity($method))) {
                    $identities[$agreement->id] = $identity;
                }
            } catch (ConnectionException | RequestException $e) {
                // A provider outage must not hide methods or prevent turning Auto Pay off.
                Log::warning('ae_stripe_method_identity_read_failed', ['agreement_id' => $agreement->id, 'error' => get_class($e)]);
            }
        }
        return $identities;
    }

    private function readSavedMethod(string $reference): ?object
    {
        try {
            return $this->request('get', '/payment_methods/' . $reference);
        } catch (RequestException $e) {
            if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') throw $e;
            return null;
        }
    }

    private function savedMethodLock(int $userId)
    {
        $driver = config('cache.stores.' . config('cache.default') . '.driver');
        if (!in_array($driver, ['database', 'redis', 'memcached', 'dynamodb'], true)) {
            throw new SavedMethodRetryException('Saved payment method changes need a shared cache store. Contact support.');
        }
        return Cache::lock('ae-stripe-save-method-' . $userId, 600);
    }

    private function savedMethodIndex($user, string $reference, $customer, $gateway): array
    {
        $index = json_decode($user->properties()->where('key', 'ae_stripe_method_' . $reference)->first()?->value ?? 'null', true);
        if (($index['customer'] ?? null) !== $customer || ($index['gateway_id'] ?? null) != $gateway->id) return [];
        if (isset($index['identity']) && (!is_string($index['identity']) || !preg_match('/\A[0-9a-f]{64}\z/', $index['identity']))) unset($index['identity']);
        return $index;
    }

    private function removeSavedMethodCopies(BillingAgreement $agreement): bool
    {
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $journalKey = 'ae_stripe_removal_' . $agreement->external_reference;
        $owner = User::where('id', $agreement->user_id)->firstOrFail();
        $previous = json_decode($owner->properties()->where('key', $journalKey)->first()?->value ?? 'null', true);
        if (($previous['gateway_id'] ?? null) == $gateway->id && ($previous['user_id'] ?? null) == $owner->id) {
            // Confirmed detach progress remains authorized even when a different planned row was reassigned.
            DB::transaction(fn () => $this->reconcileRemoval($owner, $gateway, $journalKey, $previous, $agreement->external_reference));
        }
        if (($previous['gateway_id'] ?? null) == $gateway->id && ($previous['user_id'] ?? null) == $owner->id
            && ($previous['customer'] ?? null) !== $owner->properties()->where('key', 'stripe_id')->first()?->value) {
            // A lost detach/progress response is resolved by a read, never by another owner's authorization.
            try {
                foreach ($previous['copies'] as $reference => $id) {
                    if (isset($previous['detached'][$reference])) continue;
                    $remote = $this->readSavedMethod($reference);
                    if (!$remote || (($remote->id ?? null) === $reference && ($remote->customer ?? null) === null)) $previous['detached'][$reference] = true;
                }
            } catch (ConnectionException | RequestException $e) {
                Log::warning('ae_stripe_cancel_method_read_failed', ['error' => get_class($e)]);
            }
            DB::transaction(fn () => $this->reconcileRemoval($owner, $gateway, $journalKey, $previous, ''));
            return false;
        }
        $snapshot = DB::transaction(function () use ($agreement, $gateway) {
            $user = User::where('id', $agreement->user_id)->lockForUpdate()->firstOrFail();
            $current = BillingAgreement::withTrashed()->where('external_reference', $agreement->external_reference)->lockForUpdate()->first();
            if (!$current || $current->id != $agreement->id || $current->user_id != $user->id || $current->gateway_id != $gateway->id) return null;
            return [$user, $current, $user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value,
                BillingAgreement::where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->get(),
                $user->properties()->where('key', 'like', 'ae_stripe_saved_%')->lockForUpdate()->get()];
        });
        if (!$snapshot) return false;
        [$user, $current, $customer, $rows, $receipts] = $snapshot;
        if (!$customer) return false;
        try {
            $method = $this->readSavedMethod($current->external_reference);
            if (($method->customer ?? null) !== null && ($method->customer ?? null) !== $customer) return false;
            if ($method && ($method->id ?? null) !== $current->external_reference) return false;
            $identity = $this->savedMethodIdentity($method);
            // Only a server-verified, customer-scoped index can recover a missing method's identity.
            if (!$method) $identity = $this->savedMethodIndex($user, $current->external_reference, $customer, $gateway)['identity'] ?? null;
            $copies = [$current->external_reference => $method];
            $matched = [$current];
            if ($identity !== null) {
                foreach ($rows as $row) {
                    if ($row->external_reference === $current->external_reference) continue;
                    $remote = $this->readSavedMethod($row->external_reference);
                    if (($remote->id ?? null) === $row->external_reference && in_array($remote->customer ?? null, [null, $customer], true)
                        && $this->savedMethodIdentity($remote) === $identity) {
                        $copies[$row->external_reference] = $remote;
                        $matched[] = $row;
                    }
                }
                // Saving keeps incoming copies attached for Stripe references. Their receipts survive without a local row.
                foreach ($receipts as $property) {
                    $receipt = json_decode($property->value, true);
                    $incoming = $receipt['incoming'] ?? null;
                    if (!$incoming || array_key_exists($incoming, $copies)
                        || ($receipt['consent']['user_id'] ?? null) != $user->id) continue;
                    $known = $this->savedMethodIndex($user, $incoming, $customer, $gateway);
                    if (isset($known['identity']) && $known['identity'] !== $identity) continue;
                    $remote = $this->readSavedMethod($incoming);
                    if (($remote->id ?? null) === $incoming && ($remote->customer ?? null) === $customer
                        && $this->savedMethodIdentity($remote) === $identity) $copies[$incoming] = $remote;
                }
            }
            // Resume receipt-only copies even after a previous detach lost its response or became a 404.
            if (($previous['customer'] ?? null) === $customer && ($previous['gateway_id'] ?? null) == $gateway->id) {
                foreach ($previous['copies'] as $reference => $id) {
                    if (array_key_exists($reference, $copies)) continue;
                    $remote = $this->readSavedMethod($reference);
                    if ($remote && (($remote->id ?? null) !== $reference || !in_array($remote->customer ?? null, [null, $customer], true)
                        || $this->savedMethodIdentity($remote) !== $identity)) return false;
                    $copies[$reference] = $remote;
                    if ($id !== null) {
                        $row = BillingAgreement::withTrashed()->where('external_reference', $reference)->first();
                        if (!$row || $row->id != $id || $row->user_id != $user->id || $row->gateway_id != $gateway->id) return false;
                        $matched[] = $row;
                    }
                }
            }
            $journal = DB::transaction(function () use ($user, $gateway, $customer, $copies, $matched, $journalKey, $identity) {
                User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                if ($user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value !== $customer) return false;
                foreach ($copies as $reference => $remote) {
                    $row = BillingAgreement::withTrashed()->where('external_reference', $reference)->lockForUpdate()->first();
                    if ($row && ($row->user_id != $user->id || $row->gateway_id != $gateway->id)) return false;
                }
                foreach ($matched as $match) {
                    $row = BillingAgreement::withTrashed()->where('external_reference', $match->external_reference)->lockForUpdate()->first();
                    if (!$row || $row->id != $match->id) return false;
                }
                $old = json_decode($user->properties()->where('key', $journalKey)->lockForUpdate()->first()?->value ?? 'null', true);
                $journal = ['user_id' => $user->id, 'gateway_id' => $gateway->id, 'customer' => $customer,
                    'identity' => $identity, 'copies' => [], 'detached' => []];
                if (($old['customer'] ?? null) === $customer && ($old['gateway_id'] ?? null) == $gateway->id) {
                    $journal['copies'] = $old['copies'];
                    $journal['detached'] = $old['detached'];
                }
                foreach ($copies as $reference => $remote) {
                    $journal['copies'][$reference] = null;
                    foreach ($matched as $match) if ($match->external_reference === $reference) $journal['copies'][$reference] = $match->id;
                    // A removal in progress cannot be undone by a delayed or differently sourced save.
                    $index = $this->savedMethodIndex($user, $reference, $customer, $gateway);
                    $user->properties()->updateOrCreate(['key' => 'ae_stripe_method_' . $reference], ['value' => json_encode(
                        array_replace($index, ['customer' => $customer, 'gateway_id' => $gateway->id, 'identity' => $identity, 'removing' => $journalKey]), JSON_THROW_ON_ERROR)]);
                }
                $user->properties()->updateOrCreate(['key' => $journalKey], ['value' => json_encode($journal, JSON_THROW_ON_ERROR)]);
                return $journal;
            });
            if (!$journal) return false;
            // Detach the visible method last. If any copy fails, keep the local rows and retry the same removal.
            $visible = $copies[$current->external_reference];
            unset($copies[$current->external_reference]);
            $copies[$current->external_reference] = $visible;
            foreach ($copies as $reference => $remote) {
                if (($remote->customer ?? null) === $customer) $this->request('post', '/payment_methods/' . $reference . '/detach');
                $journal['detached'][$reference] = true;
                DB::transaction(function () use ($user, $gateway, $customer, $reference, $journalKey, $journal) {
                    User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                    $index = $this->savedMethodIndex($user, $reference, $customer, $gateway);
                    unset($index['removing']);
                    $index['removed'] = true;
                    $user->properties()->updateOrCreate(['key' => 'ae_stripe_method_' . $reference], ['value' => json_encode($index, JSON_THROW_ON_ERROR)]);
                    $user->properties()->updateOrCreate(['key' => $journalKey], ['value' => json_encode($journal, JSON_THROW_ON_ERROR)]);
                });
            }
        } catch (ConnectionException | RequestException $e) {
            Log::warning('ae_stripe_cancel_method_read_failed', ['error' => get_class($e)]);
            if (isset($journal) && is_array($journal)) DB::transaction(fn () => $this->reconcileRemoval($user, $gateway, $journalKey, $journal, ''));
            return false;
        }
        $removed = DB::transaction(fn () => $this->reconcileRemoval($user, $gateway, $journalKey, $journal, $current->external_reference));
        if ($removed && !$method && $identity === null) {
            $notice = ['type' => 'warning', 'message' => 'This payment method was already gone. We removed its saved entry, but could not verify or remove other copies.'];
            \Illuminate\Support\Facades\Session::flash('notification', $notice);
            // Native removal runs after bootHasNotifications. Notify before Livewire collects dispatched events.
            if (function_exists('Livewire\\on')) app(\Livewire\EventBus::class)->before('dehydrate', static function ($component) use ($notice) {
                if (\Illuminate\Support\Facades\Session::get('notification') !== $notice || !method_exists($component, 'notify')) return;
                \Illuminate\Support\Facades\Session::forget('notification');
                $component->notify($notice['message'], $notice['type']);
            });
        }
        return $removed;
    }

    // The journal records the original authorization and proven provider outcomes, independently of stripe_id changes.
    private function reconcileRemoval($user, $gateway, string $key, array $journal, string $clicked): bool
    {
        User::where('id', $user->id)->lockForUpdate()->firstOrFail();
        $valid = $user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value === $journal['customer'];
        $owned = [];
        foreach ($journal['copies'] as $reference => $id) {
            if ($id === null) continue;
            $row = BillingAgreement::withTrashed()->where('external_reference', $reference)->lockForUpdate()->first();
            if (!$row || $row->id != $id || $row->user_id != $user->id || $row->gateway_id != $gateway->id) {
                $valid = false;
                continue;
            }
            $owned[$reference] = $row;
        }
        foreach ($owned as $reference => $row) {
            if (!isset($journal['detached'][$reference])) continue;
            $this->autoPayCardRemoved($user, (int) $row->id);
            // Leave core its normal clicked-row deletion only when final validation succeeds.
            if (($reference !== $clicked || !$valid) && !$row->trashed()) $row->delete();
        }
        foreach ($user->properties()->where('key', 'like', 'ae_stripe_saved_%')->lockForUpdate()->get() as $property) {
            $receipt = json_decode($property->value, true);
            if (($receipt['consent']['user_id'] ?? null) != $user->id
                || (!isset($journal['detached'][$receipt['incoming'] ?? '']) && !isset($journal['detached'][$receipt['reference'] ?? '']))) continue;
            $receipt['removed'] = true;
            $user->properties()->updateOrCreate(['key' => $property->key], ['value' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
        }
        $journal['complete'] = count($journal['copies']) === count($journal['detached']);
        $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($journal, JSON_THROW_ON_ERROR)]);
        return $valid && $journal['complete'];
    }

    private function persistAgreement($user, $method, array $consent = [])
    {
        if (empty($method->id)) return false;
        // Serialize provider updates without holding a database owner lock across HTTP. Contention is retryable.
        $lock = $this->savedMethodLock((int) $user->id);
        try {
            // A second browser return waits for the first receipt, then returns the same saved result.
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            throw new SavedMethodRetryException('Another saved payment method update is in progress. Try again.');
        }
        try {
            return $this->persistSavedMethod($user, $method, $consent);
        } finally {
            $lock->release();
        }
    }

    private function persistSavedMethod($user, $method, array $consent)
    {
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $incomingId = $method->id;
        $source = $consent['source'] ?? $incomingId;
        $key = 'ae_stripe_saved_' . hash('sha256', $source . ':' . $incomingId);
        // Current reads include saves committed after an earlier REPEATABLE READ snapshot.
        [$customer, $rows, $receipt, $receipts] = DB::transaction(function () use ($user, $gateway, $key) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            return [$user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value,
                BillingAgreement::where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->get(),
                json_decode($user->properties()->where('key', $key)->lockForUpdate()->first()?->value ?? 'null', true),
                $user->properties()->where('key', 'like', 'ae_stripe_saved_%')->lockForUpdate()->get()];
        });
        if (!$customer) return false;
        $consent = DB::transaction(function () use ($user, $key, $consent, $incomingId, &$receipt) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            if (!$receipt) {
                $sequenceKey = 'ae_stripe_save_sequence';
                $sequence = 1 + (int) ($user->properties()->where('key', $sequenceKey)->lockForUpdate()->first()?->value ?? 0);
                $user->properties()->updateOrCreate(['key' => $sequenceKey], ['value' => (string) $sequence]);
                $receipt = ['incoming' => $incomingId, 'consent' => $consent + ['accepted_at' => time(), 'user_id' => $user->id, 'sequence' => $sequence]];
                $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
            }
            if (($receipt['incoming'] ?? null) !== $incomingId) throw new DisplayException('This saved payment method changed. Add it again.');
            if ($receipt['removed'] ?? false) throw new DisplayException('This payment method was removed. Add a new payment method.');
            return $receipt['consent'];
        });
        if ($this->savedMethodIndex($user, $incomingId, $customer, $gateway)['removed'] ?? false) {
            throw new DisplayException('This payment method was removed. Add a new payment method.');
        }
        if (isset($this->savedMethodIndex($user, $incomingId, $customer, $gateway)['removing'])) {
            throw new SavedMethodRetryException('This payment method is being removed. Retry the removal, or add the card again.');
        }
        $method = $this->request('get', '/payment_methods/' . $incomingId);
        if (($method->customer ?? null) === null) {
            // An unfinished return from the previous release may refer to a duplicate it already detached.
            $legacy = json_decode($user->properties()->where('key', 'ae_stripe_duplicate_' . $incomingId)->first()?->value ?? 'null');
            if (($legacy->customer ?? null) === $customer && ($legacy->gateway ?? null) == $gateway->id) {
                $retained = null;
                foreach ($rows as $row) if ($row->external_reference === ($legacy->kept ?? null)) $retained = $row;
                if (!$retained) throw new DisplayException('This payment method was removed. Add a new payment method.');
                $remote = $this->request('get', '/payment_methods/' . $retained->external_reference);
                if (($remote->customer ?? null) === $customer && ($identity = $this->savedMethodIdentity($method)) !== null
                    && $this->savedMethodIdentity($remote) === $identity) {
                    if ($this->savedMethodUnavailable($user, $retained->external_reference, $customer, $gateway)) throw new SavedMethodRetryException(self::BINDING_RETRY);
                    return ['message' => 'This card is already saved. We updated its expiry to '
                        . substr($retained->expiry, 5, 2) . '/' . substr($retained->expiry, 0, 4) . '.'];
                }
            }
        }
        if (!$this->methodCustomerMatches($user, $method)) return false;
        $identity = $this->savedMethodIdentity($method);
        $remotes = [$incomingId => $method];
        $matches = [];
        foreach ($rows as $row) {
            if (($method->type ?? null) === 'link' && $row->type !== 'link') continue;
            // Local card types are display brands. Verify the actual Stripe identity for every brand.
            $known = json_decode($user->properties()->where('key', 'ae_stripe_method_' . $row->external_reference)->first()?->value ?? 'null', true);
            // A fresh provider reference can replace a failed removal without reusing its uncertain copies.
            if (($known['customer'] ?? null) === $customer && ($known['gateway_id'] ?? null) == $gateway->id
                && (isset($known['removing']) || ($known['removed'] ?? false))) continue;
            if ($identity !== null && ($known['customer'] ?? null) === $customer && ($known['gateway_id'] ?? null) == $gateway->id
                && ($known['identity'] ?? null) !== null && $known['identity'] !== $identity) continue;
            if ($row->external_reference !== $incomingId && $identity !== null) {
                try {
                    $remotes[$row->external_reference] = $this->request('get', '/payment_methods/' . $row->external_reference);
                } catch (RequestException $e) {
                    if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') throw $e;
                    Log::warning('ae_stripe_duplicate_candidate_missing', ['error' => get_class($e)]);
                    continue;
                }
            }
            $remote = $remotes[$row->external_reference] ?? null;
            if ($remote && ($remote->id ?? null) === $row->external_reference && ($remote->customer ?? null) === $customer
                && ($row->external_reference === $incomingId || ($identity !== null && $this->savedMethodIdentity($remote) === $identity))) $matches[] = $row;
        }
        usort($matches, fn ($a, $b) => strcmp((string) $b->created_at, (string) $a->created_at) ?: $b->id <=> $a->id);
        // Reserved attached copies still need expiry refresh, but cannot be the saved selection.
        $selectable = array_values(array_filter($matches, fn ($row) => !$this->savedMethodUnavailable($user, $row->external_reference, $customer, $gateway)));
        $kept = $selectable[0] ?? null;
        if (!$kept && $this->savedMethodUnavailable($user, $incomingId, $customer, $gateway)) throw new SavedMethodRetryException(self::BINDING_RETRY);
        // Validate globally unique references before any provider writes, including removed or foreign rows.
        $validate = function (bool $checkRows = true, array $references = []) use ($user, $gateway, $customer, $incomingId, $rows) {
            foreach (array_unique([$incomingId, ...$references]) as $reference) {
                $row = BillingAgreement::withTrashed()->where('external_reference', $reference)->lockForUpdate()->first();
                if ($row && ($row->user_id != $user->id || $row->gateway_id != $gateway->id)) {
                    throw new DisplayException('This payment method can\'t be used on this account. Add a new payment method.');
                }
                if ($row?->trashed()) throw new DisplayException('This payment method was removed. Add a new payment method.');
                if ($this->savedMethodIndex($user, $reference, $customer, $gateway)['removed'] ?? false) {
                    throw new DisplayException('This payment method was removed. Add a new payment method.');
                }
            }
            if (!$checkRows) return;
            $current = BillingAgreement::where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->get();
            $references = static function ($items) {
                $result = [];
                foreach ($items as $item) $result[$item->id] = $item->external_reference;
                ksort($result);
                return $result;
            };
            if ($references($current) !== $references($rows)) throw new SavedMethodRetryException('Saved payment methods changed. Try again.');
        };
        DB::transaction(function () use ($user, $customer, $validate) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value === $customer, 409);
            $validate();
        });
        if (isset($receipt['reference'])) {
            // Retries never roll a later expiry or acceptance back to this source's older values.
            $active = DB::transaction(function () use ($user, $gateway, $receipt) {
                User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                return BillingAgreement::where('external_reference', $receipt['reference'])->where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->first();
            });
            if (!$active) throw new DisplayException('This payment method was removed. Add a new payment method.');
            if ($this->savedMethodUnavailable($user, $active->external_reference, $customer, $gateway)) throw new SavedMethodRetryException(self::BINDING_RETRY);
            return $receipt['message'] ? ['message' => $receipt['message']] : true;
        }
        $data = $this->getPaymentDetails($method);
        $duplicate = $kept !== null;
        $reference = $kept?->external_reference ?? $incomingId;
        $targets = [$incomingId => null];
        foreach ($matches as $match) $targets[$match->external_reference] = $match;
        $localReferences = [];
        foreach ($rows as $row) $localReferences[$row->external_reference] = true;
        if ($identity !== null) foreach ($receipts as $property) {
            $stored = json_decode($property->value, true);
            $incoming = $stored['incoming'] ?? null;
            if (!$incoming || array_key_exists($incoming, $targets) || isset($localReferences[$incoming])
                || ($stored['removed'] ?? false) || ($stored['consent']['user_id'] ?? null) != $user->id) continue;
            $known = $this->savedMethodIndex($user, $incoming, $customer, $gateway);
            if (isset($known['removing'])) continue;
            if (isset($known['identity']) && $known['identity'] !== $identity) continue;
            $remote = $this->readSavedMethod($incoming);
            if (($remote->id ?? null) === $incoming && ($remote->customer ?? null) === $customer
                && $this->savedMethodIdentity($remote) === $identity) $targets[$incoming] = null;
        }
        $references = array_keys($targets);
        DB::transaction(function () use ($user, $customer, $validate, $references) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value === $customer, 409);
            $validate(true, $references);
        });
        $updates = [];
        foreach ($targets as $target => $row) {
            $latest = json_decode($user->properties()->where('key', 'ae_stripe_method_' . $target)->first()?->value ?? 'null', true);
            $older = ($latest['customer'] ?? null) === $customer && ($latest['gateway_id'] ?? null) == $gateway->id
                && [($latest['consent']['accepted_at'] ?? 0), ($latest['consent']['sequence'] ?? 0)] > [$consent['accepted_at'], $consent['sequence']];
            $updates[$target] = ['older' => $older, 'data' => $older && $row
                ? ['name' => $row->name, 'type' => $row->type, 'expiry' => $row->expiry] : $data];
            $update = ['allow_redisplay' => 'always'];
            if (!$older && $target !== $incomingId && ($method->type ?? null) === 'card') {
                $update['card'] = ['exp_month' => $method->card->exp_month, 'exp_year' => $method->card->exp_year];
            }
            // Every retained copy can still be used by Auto Pay, a service or a Stripe subscription.
            // Update them all without holding the database owner lock. Never detach on save.
            $this->request('post', '/payment_methods/' . $target, $update);
        }
        $data = $updates[$reference]['data'];
        $message = $duplicate ? (($method->type ?? null) === 'card'
            ? 'This card is already saved. We updated its expiry to ' . substr($data['expiry'], 5, 2) . '/' . substr($data['expiry'], 0, 4) . '.'
            : 'This Link account is already saved. We updated it.') : null;
        return DB::transaction(function () use ($user, $gateway, $customer, $incomingId, $reference, $kept, $rows, $data, $identity, $consent, $key, $message, $validate, $updates, $matches, $receipt, $references) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value === $customer, 409);
            $validate(true, $references);
            $agreement = $kept;
            if (!$agreement) {
                try {
                    $agreement = ExtensionHelper::makeBillingAgreement($user, 'AeStripe', $data['name'], $incomingId, $data['type'], $data['expiry']);
                } catch (UniqueConstraintViolationException $e) {
                    $agreement = BillingAgreement::withTrashed()->where('external_reference', $incomingId)->lockForUpdate()->first();
                    if (!$agreement) throw $e;
                    // The insert itself found a new row; still recheck its owner, gateway and deletion.
                    $validate(false, $references);
                }
            }
            $local = $matches ?: [$agreement];
            foreach ($local as $row) {
                $change = $updates[$row->external_reference];
                if (!$change['older']) $row->update($change['data']);
            }
            foreach ($updates as $target => $change) {
                if ($change['older']) continue;
                $user->properties()->updateOrCreate(['key' => 'ae_stripe_method_' . $target], ['value' => json_encode([
                    'customer' => $customer, 'gateway_id' => $gateway->id, 'identity' => $identity,
                    'consent' => $consent + ['accepted_at' => time(), 'user_id' => $user->id],
                ], JSON_THROW_ON_ERROR)]);
            }
            $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($receipt + ['reference' => $reference, 'message' => $message], JSON_THROW_ON_ERROR)]);
            return $message ? ['message' => $message] : true;
        });
    }
}
