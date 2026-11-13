<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use Paymenter\Extensions\Gateways\AeStripe\ExtensionLocks;

use App\Models\{BillingAgreement, Gateway, Invoice, Property, Service, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Log};

trait AutoPay
{
    // Account scope: every recurring service, current and new. Older new-services-only consent does not carry over.
    // v2 is the sentence in autoPayConsent(); new turn-ons store it. v1 records, accepted under the same scope with
    // longer words, stay valid until the customer turns Auto Pay off.
    public const AUTO_PAY_CONSENT = 'auto_pay_account_v2';
    private const AUTO_PAY_CONSENTS = ['auto_pay_account_v1', self::AUTO_PAY_CONSENT];
    private const AUTO_PAY_PROPERTY = 'ae_stripe_auto_pay_default';
    private const AUTO_PAY_CARD = 'ae_stripe_auto_pay_card';
    // Counts every Off and every new turn-on. A consent given before the count moved is older than the customer's
    // latest choice, so a payment that lands later never turns Auto Pay on over it.
    private const AUTO_PAY_GENERATION = 'ae_stripe_auto_pay_gen';
    // A saved-card turn-on and pay's consent, per invoice, bound to one payment before it is charged.
    private const AUTO_PAY_CONSENT_KEY = 'ae_stripe_auto_pay_consent_';

    /**
     * The one Auto Pay consent, shown word for word wherever Auto Pay can be turned on: the pay dialog here, and any
     * theme's saved-card box or turn-on dialog. A theme renders `text`, then a `terms_label` link to `terms_url` when
     * it is set, under a `label` box or button, and sends `version` back with the turn-on.
     *
     * @param  string|null  $card  "•••• 4242", "your Link wallet", or null for a card being entered ("this card")
     * @return array{version: string, label: string, text: string, terms_label: string, terms_url: ?string}
     */
    public function autoPayConsent(?string $card = null): array
    {
        $terms = trim((string) config('settings.tos', ''));
        return [
            'version' => self::AUTO_PAY_CONSENT,
            'label' => __('Turn on Auto Pay'),
            'text' => __('Charges :card for every renewal invoice. Turn off any time in Payment methods.',
                ['card' => $card ?? __('this card')]),
            'terms_label' => __('Terms'),
            'terms_url' => $terms === '' ? null : $terms,
        ];
    }

    /** Whether the pay dialog offers "Turn on Auto Pay": only while it is off and this gateway takes card payments. */
    public function autoPayOffered(User $user): bool
    {
        return $this->gatewayEnabled() && $this->bundledInactive() && !$this->autoPayOn($user);
    }

    /**
     * Auto Pay is on for the account with a default, or while any renewing service is linked to a saved method here.
     * Turning it on links every service to one card, so it is never offered over services already set one by one.
     */
    private function autoPayOn(User $user, bool $lock = false): bool
    {
        if ($this->autoPayDefault($user, lock: $lock)) return true;
        $gateway = Gateway::where('extension', 'AeStripe')->first();
        if (!$gateway) return false;
        foreach ($this->autoPayServices($user) as $service) {
            if (!$service->billing_agreement_id || ($service->plan?->type ?? null) !== 'recurring'
                || $service->status === Service::STATUS_CANCELLED) continue;
            $agreement = BillingAgreement::where('id', $service->billing_agreement_id)->where('user_id', $user->id)
                ->where('gateway_id', $gateway->id)->first();
            if ($agreement && empty($agreement->deleted_at)) return true;
        }
        return false;
    }

    /** The Auto Pay choice count, read when consent is given and checked again before that consent turns Auto Pay on. */
    public function autoPayGeneration(User $user, bool $lock = false): int
    {
        $key = self::AUTO_PAY_GENERATION;
        return (int) (($lock ? $this->lockedUserProperty($user, $key) : $user->properties()->where('key', $key)->first())?->value ?? 0);
    }

    /**
     * The pay dialog's Auto Pay consent as PaymentIntent metadata: the words' version, when it was given, and the
     * choice count then. turnOnPaidAutoPay() honours it only while the count is unchanged.
     */
    private function autoPayIntentConsent(User $user, bool $autoPay): array
    {
        return ['ae_stripe_auto_pay' => $autoPay ? self::AUTO_PAY_CONSENT : '',
            'ae_stripe_auto_pay_at' => $autoPay ? (string) time() : '',
            'ae_stripe_auto_pay_gen' => $autoPay ? (string) $this->autoPayGeneration($user) : ''];
    }

    /** The caller holds the user lock. */
    private function nextAutoPayGeneration(User $user): int
    {
        $next = $this->autoPayGeneration($user, lock: true) + 1;
        $user->properties()->updateOrCreate(['key' => self::AUTO_PAY_GENERATION], ['value' => (string) $next]);
        return $next;
    }

    /** A saved card or off-session saving consent alone never grants this scope. */
    public function autoPayDefault(User $user, bool $lock = false): ?array
    {
        $key = self::AUTO_PAY_PROPERTY;
        $value = ($lock ? $this->lockedUserProperty($user, $key) : $user->properties()->where('key', $key)->first())?->value;
        $record = $value ? json_decode($value, true) : null;
        if (!is_array($record) || !in_array($record['version'] ?? null, self::AUTO_PAY_CONSENTS, true)
            || ($record['user_id'] ?? null) != $user->id || empty($record['accepted_at'])
            || !is_int($record['agreement_id'] ?? null)) return null;
        $gateway = Gateway::where('extension', 'AeStripe')->first();
        $query = $gateway ? BillingAgreement::where('id', $record['agreement_id'])
            ->where('user_id', $user->id)->where('gateway_id', $gateway->id) : null;
        $agreement = $query ? ($lock ? $query : $query)->first() : null;
        return $agreement ? $record : null;
    }

    /** The customer's saved choice, independent of charging consent. Older enabled accounts keep their card. */
    public function autoPayCard(User $user, bool $lock = false): ?int
    {
        if ($default = $this->autoPayDefault($user, $lock)) return $default['agreement_id'];
        $key = self::AUTO_PAY_CARD;
        $value = ($lock ? $this->lockedUserProperty($user, $key) : $user->properties()->where('key', $key)->first())?->value;
        $record = $value ? json_decode($value, true) : null;
        if (!is_array($record)) return null;
        if (($record['user_id'] ?? null) != $user->id || !is_int($record['agreement_id'] ?? null)) return null;
        $gateway = Gateway::where('extension', 'AeStripe')->first();
        $query = $gateway ? BillingAgreement::where('id', $record['agreement_id'])->where('user_id', $user->id)
            ->where('gateway_id', $gateway->id) : null;
        return $query && ($lock ? $query : $query)->first() ? $record['agreement_id'] : null;
    }

    private function rememberAutoPayCard(User $user, int $agreementId): void
    {
        $user->properties()->updateOrCreate(['key' => self::AUTO_PAY_CARD], ['value' => json_encode([
            'user_id' => (int) $user->id, 'agreement_id' => $agreementId,
        ], JSON_THROW_ON_ERROR)]);
    }

    /**
     * Authenticated account switch, independent of paying any invoice. Off needs no consent. On links every recurring
     * service to the chosen card; off unlinks them all, including links set per service before the account switch.
     * Open invoices are never charged by this: native renewal charges happen only when an invoice is issued, and either
     * way the switch stops any waiting renewal retry. Stripe subscriptions keep their own billing.
     */
    public function saveAutoPayDefault(Request $request)
    {
        $request->validate([
            'enabled' => 'sometimes|boolean',
            'agreement_id' => 'required_without:enabled|required_if:enabled,true|integer|min:1',
            'consent' => 'accepted_if:enabled,true',
            'version' => 'required_if:enabled,true|string',
        ]);
        abort_unless(!$request->boolean('enabled') || $this->gatewayEnabled() && $this->bundledInactive(), 409);
        $change = fn () => ExtensionLocks::transaction('user:' . Auth::id(), function () use ($request) {
            $user = User::where('id', Auth::id())->firstOrFail();
            // A picker change alone saves a preference, never enables or disables any service.
            if ($request->input('enabled') === null) {
                $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
                $agreement = BillingAgreement::where('id', $request->input('agreement_id'))
                    ->where('user_id', $user->id)->where('gateway_id', $gateway->id)->first();
                abort_unless($agreement, 403);
                $this->assertSavedMethodBindable($user, $agreement);
                $this->rememberAutoPayCard($user, (int) $agreement->id);
                return response()->json(['ok' => true]);
            }
            if (!$request->boolean('enabled')) {
                $card = $this->autoPayDefault($user, lock: true)['agreement_id'] ?? null;
                if ($card) $this->rememberAutoPayCard($user, $card);
                $this->stopWaitingRenewals($user);
                // Off is the latest word: any consent still waiting on a payment is now older and cannot turn it on.
                $this->nextAutoPayGeneration($user);
                $user->properties()->where('key', self::AUTO_PAY_PROPERTY)->delete();
                foreach ($this->autoPayServices($user) as $service) {
                    $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
                    if ($service->billing_agreement_id) $service->update(['billing_agreement_id' => null]);
                }
                return response()->json(['ok' => true, 'enabled' => false]);
            }
            abort_unless($request->boolean('consent') && $request->input('version') === self::AUTO_PAY_CONSENT, 422);
            $services = $this->enableAutoPay($user, (int) $request->input('agreement_id'));
            return response()->json(['ok' => true, 'enabled' => true, 'services' => $services]);
        });
        if ($request->input('enabled') !== null && !$request->boolean('enabled')) return $change();
        try {
            return $this->withSavedMethodBindingLock((int) Auth::id(), $change);
        } catch (\Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /** How the consent line and the turn-on email name a saved method: "•••• 4242" or "your Link wallet". */
    public function autoPayCardLabel(BillingAgreement $agreement): string
    {
        if ($agreement->type === 'link') return __('your Link wallet');
        return preg_match('/\\*{4} ?([0-9]{4})$/', (string) $agreement->name, $last4) ? '•••• ' . $last4[1] : (string) $agreement->name;
    }

    /**
     * On with the chosen card: links every recurring service to it, stores the consent record and queues the
     * "Auto Pay is on" email. The same card already on is the same enrollment: services are linked again, the record
     * and the count stay, and no second email goes out. The caller holds the user row lock.
     */
    private function enableAutoPay(User $user, int $agreementId, ?int $acceptedAt = null, string $version = self::AUTO_PAY_CONSENT): int
    {
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $agreement = BillingAgreement::where('id', $agreementId)
            ->where('user_id', $user->id)->where('gateway_id', $gateway->id)->first();
        abort_unless($agreement, 403);
        $this->assertSavedMethodBindable($user, $agreement);
        $this->stopWaitingRenewals($user);
        $this->rememberAutoPayCard($user, (int) $agreement->id);
        if (($this->autoPayDefault($user, lock: true)['agreement_id'] ?? null) !== (int) $agreement->id) {
            $generation = $this->nextAutoPayGeneration($user);
            $record = ['version' => $version, 'user_id' => (int) $user->id,
                'accepted_at' => $acceptedAt ?? time(), 'agreement_id' => (int) $agreement->id];
            $user->properties()->updateOrCreate(['key' => self::AUTO_PAY_PROPERTY], ['value' => json_encode($record, JSON_THROW_ON_ERROR)]);
            $this->queueAutoPayOnMail($user, $agreement, $generation);
        }
        $services = 0;
        foreach ($this->autoPayServices($user) as $service) {
            if (($service->plan?->type ?? null) !== 'recurring' || $service->status === Service::STATUS_CANCELLED) continue;
            if ($service->billing_agreement_id != $agreement->id) $service->update(['billing_agreement_id' => (int) $agreement->id]);
            $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
            $services++;
        }
        return $services;
    }

    /**
     * Turn on and pay, from the one consent in the Turn on dialog. The consent is stored before any charge, bound to
     * this payment: user, invoice, card, words, time, the Auto Pay choice count and a random id that goes on the
     * PaymentIntent. Only recordIntentPayment() decides what it is worth: a success of that exact intent with that
     * card marks it paid, and its failure, or another payment of the invoice, ends it. Auto Pay turns on from a paid
     * consent only (enrollPaidAutoPayConsent), never from the invoice's status. Once it is on, the other invoices
     * are paid as Auto Pay payments, each under the gateway's Auto Pay card check. The caller checks that each
     * invoice is the customer's own, open, and at the amount shown.
     *
     * @param  array<int, array{0: Invoice, 1: string}>  $invoices  invoice and accepted amount, oldest first
     * @return array{enabled: bool, invoices: array<int, array{id: int, status: string, message?: string}>, message: ?string}
     */
    public function turnOnAutoPayAndPay(User $user, BillingAgreement $agreement, array $invoices, string $version): array
    {
        $result = ['enabled' => false, 'invoices' => [], 'message' => null];
        if ($version !== self::AUTO_PAY_CONSENT || !$invoices) {
            return ['message' => 'Refresh the page and try again.'] + $result;
        }
        if (!$this->gatewayEnabled() || !$this->bundledInactive()) {
            return ['message' => 'Payments are paused. Please check back later.'] + $result;
        }
        [$first, $amount] = $invoices[0];
        $key = self::AUTO_PAY_CONSENT_KEY . $first->id;
        // The dialog was offered while Auto Pay was off; a choice made since then is newer than this consent.
        $consent = ExtensionLocks::transaction('user:' . $user->id, function () use ($user, $agreement, $first, $key) {
            $locked = User::where('id', $user->id)->firstOrFail();
            if ($this->autoPayOn($locked, lock: true)) return null;
            $consent = ['id' => 'apc_' . bin2hex(random_bytes(12)), 'user_id' => (int) $locked->id, 'invoice_id' => (int) $first->id,
                'agreement_id' => (int) $agreement->id, 'version' => self::AUTO_PAY_CONSENT, 'accepted_at' => time(),
                'generation' => $this->autoPayGeneration($locked, lock: true), 'state' => 'charging', 'intent' => null];
            $locked->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($consent, JSON_THROW_ON_ERROR)]);
            return $consent;
        });
        if (!$consent) return ['message' => 'Auto Pay changed. Refresh the invoice before paying.'] + $result;
        $pay = function (Invoice $invoice, string $amount, bool $autoPay, ?string $consentId = null) use ($agreement): array {
            try {
                $autoPay ? $this->chargeAutoPayAccepted($invoice, $amount, $agreement) : $this->chargeAccepted($invoice, $amount, $agreement, false, $consentId);
            } catch (\Paymenter\Extensions\Gateways\AeStripe\PaymentPendingException $e) {
                return ['id' => (int) $invoice->id, 'status' => 'processing'];
            } catch (\App\Exceptions\DisplayException $e) {
                return ['id' => (int) $invoice->id, 'status' => $invoice->fresh()->status, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                report($e);
                return ['id' => (int) $invoice->id, 'status' => $invoice->fresh()->status, 'message' => 'Could not take this payment. Try again or contact support.'];
            }
            return ['id' => (int) $invoice->id, 'status' => $invoice->fresh()->status];
        };
        $paid = $pay($first, $amount, false, $consent['id']);
        $result['invoices'][] = ['id' => $paid['id'], 'status' => $paid['status']];
        // The settlement already acted on this consent; only its record says whether this payment turned Auto Pay on.
        $this->enrollPaidAutoPayConsent((int) $user->id, $key, $consent['id']);
        $state = ExtensionLocks::transaction('user:' . $user->id, function () use ($user, $key, $consent, $paid) {
            $locked = User::where('id', $user->id)->firstOrFail();
            $stored = json_decode($this->lockedUserProperty($locked, $key)?->value ?? 'null', true);
            if (($stored['id'] ?? null) !== $consent['id']) return 'ended';
            // A paid consent stays for retryAutoPayConsents() when this enrollment could not finish.
            if (in_array($stored['state'], ['enrolled', 'changed', 'failed'], true) || $stored['state'] === 'charging' && $paid['status'] !== 'processing') {
                $locked->properties()->where('key', $key)->delete();
            }
            return $stored['state'];
        });
        if ($state === 'enrolled') {
            $result['enabled'] = true;
            foreach (array_slice($invoices, 1) as [$invoice, $amount]) {
                // A later invoice that is not paid keeps its own reason.
                $result['invoices'][] = array_intersect_key($pay($invoice, $amount, true), array_flip(['id', 'status', 'message']));
            }
            return $result;
        }
        if ($state === 'changed') return ['message' => 'The payment went through. Auto Pay was changed in another window, so that choice stays.'] + $result;
        if ($state === 'paid') return ['message' => 'The payment went through. Auto Pay turns on in a few minutes.'] + $result;
        if ($state === 'failed') return ['message' => 'The payment went through, but this card is no longer saved, so Auto Pay is still off. Add a card in Payment methods to turn it on.'] + $result;
        if ($state === 'charging' && $paid['status'] === 'processing') return ['message' => 'The payment is processing. Auto Pay turns on once it goes through.'] + $result;
        if (isset($paid['message'])) return ['message' => $paid['message']] + $result;
        // Ended without this payment: it failed, or another payment paid the invoice first.
        return ['message' => $paid['status'] === 'paid' ? 'This invoice was already paid, so Auto Pay was not turned on.' : 'This payment was not completed.'] + $result;
    }


    /**
     * The pay dialog's "Turn on Auto Pay" for a new card, once its payment succeeded and the card was saved. Every
     * path that saves the card calls this (the dialog, the bank return, the webhook), and a marker per payment makes
     * it happen once, so a later Off is never undone by a late webhook. Auto Pay turned on elsewhere since the dialog
     * opened keeps its own card. A failure leaves no marker, so the next path tries again; the payment stands.
     */
    private function turnOnPaidAutoPay($intent): void
    {
        $version = $intent->metadata->ae_stripe_auto_pay ?? null;
        if (!in_array($version, self::AUTO_PAY_CONSENTS, true) || ($intent->status ?? null) !== 'succeeded'
            || !is_string($intent->payment_method ?? null) || !is_string($intent->id ?? null)) return;
        $invoice = Invoice::where('id', $intent->metadata->invoice_id ?? null)->first();
        if (!$invoice || !$this->gatewayEnabled() || !$this->bundledInactive()) return;
        $userId = (int) $invoice->user_id;
        $marker = 'ae_stripe_auto_pay_paid_' . $intent->id;
        try {
            $this->withSavedMethodBindingLock($userId, fn () => ExtensionLocks::transaction('user:' . $userId, function () use ($intent, $userId, $marker, $version) {
                $user = User::where('id', $userId)->firstOrFail();
                if ($this->lockedUserProperty($user, $marker)) return;
                // The save receipt names the card kept for this payment, which is an older copy when it was a duplicate.
                $receipt = json_decode($user->properties()->where('key', 'ae_stripe_saved_' . hash('sha256', $intent->id . ':' . $intent->payment_method))->first()?->value ?? 'null', true);
                $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
                $agreement = is_string($receipt['reference'] ?? null) ? BillingAgreement::where('user_id', $userId)
                    ->where('gateway_id', $gateway->id)->where('external_reference', $receipt['reference'])->first() : null;
                if (!$agreement) return;
                // On elsewhere, by default or service by service, keeps its cards, and any On or Off since the dialog
                // confirmed is newer than this consent; either way this payment changes nothing.
                $generation = (string) ($intent->metadata->ae_stripe_auto_pay_gen ?? '');
                if ($generation === (string) $this->autoPayGeneration($user, lock: true) && !$this->autoPayOn($user, lock: true)) {
                    $this->enableAutoPay($user, (int) $agreement->id, (int) ($intent->metadata->ae_stripe_auto_pay_at ?? 0) ?: null, $version);
                }
                $user->properties()->updateOrCreate(['key' => $marker], ['value' => json_encode([
                    'agreement_id' => (int) $agreement->id, 'at' => time()], JSON_THROW_ON_ERROR)]);
            }));
        } catch (\Throwable $e) {
            if (!$e instanceof \Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException) report($e);
            Log::warning('AeStripe Auto Pay after payment will retry', ['invoice_id' => $invoice->id]);
        }
    }

    /**
     * Settlement's half of a turn-on and pay, called by recordIntentPayment() under the user and invoice locks for
     * every payment it records on an invoice. The consent counts only for its own PaymentIntent: a success of that
     * intent with the consent's card marks it paid; its failure, or a success of any other payment, ends it.
     */
    private function settleAutoPayConsent(User $user, $invoice, $intent, string $status): void
    {
        $key = self::AUTO_PAY_CONSENT_KEY . $invoice->id;
        $consent = json_decode($this->lockedUserProperty($user, $key)?->value ?? 'null', true);
        if (!is_array($consent) || !in_array($status, ['succeeded', 'failed'], true) || ($consent['state'] ?? null) !== 'charging') return;
        $own = is_string($consent['id'] ?? null) && ($intent->metadata->ae_stripe_auto_pay_consent ?? null) === $consent['id'];
        if ($status === 'failed') {
            if ($own) $user->properties()->where('key', $key)->delete();
            return;
        }
        $agreement = is_int($consent['agreement_id'] ?? null) ? BillingAgreement::where('id', $consent['agreement_id'])
            ->where('user_id', $user->id)->first() : null;
        if (!$own || !$agreement || $agreement->external_reference !== ($intent->payment_method ?? null)) {
            $user->properties()->where('key', $key)->delete();
            return;
        }
        $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode(
            array_replace($consent, ['state' => 'paid', 'intent' => $intent->id]), JSON_THROW_ON_ERROR)]);
    }

    /**
     * Turns Auto Pay on from a paid consent, after its settlement committed: the saved-method lock comes before the
     * user lock, so it cannot run inside settlement. Every later record of the same payment (bank return, webhook,
     * recovery) calls it again, and retryAutoPayConsents() retries what none of them finished; both act only on a
     * paid consent and end it the same way:
     * - enrolled: the consent's card is enabled;
     * - changed: Auto Pay is on, or the customer made a newer choice (Off) since, so their choice stays. This is
     *   checked first, so a newer choice is never reported as a failure, even when the card is gone too;
     * - failed: with no newer choice, the consent's card is no longer saved. The customer gets one email and staff
     *   one notice.
     * Any error leaves it paid and retries with backoff, without a cap; staff get one notice once it keeps failing.
     * The payment stands either way.
     */
    private function enrollPaidAutoPayConsent(int $userId, string $key, ?string $consentId = null, ?string $intentId = null): void
    {
        $peek = json_decode(User::where('id', $userId)->first()?->properties()->where('key', $key)->first()?->value ?? 'null', true);
        if (($peek['state'] ?? null) !== 'paid' || $consentId && $peek['id'] !== $consentId || $intentId && $peek['intent'] !== $intentId) return;
        if (!$this->gatewayEnabled() || !$this->bundledInactive()) return;
        try {
            $this->withSavedMethodBindingLock($userId, fn () => ExtensionLocks::transaction('user:' . $userId, function () use ($userId, $key, $peek) {
                $user = User::where('id', $userId)->firstOrFail();
                $consent = json_decode($this->lockedUserProperty($user, $key)?->value ?? 'null', true);
                if (($consent['state'] ?? null) !== 'paid' || $consent['id'] !== $peek['id']) return;
                // A newer choice comes first: whatever happened to the card since, the customer's own choice stands.
                $state = match (true) {
                    $this->autoPayOn($user, lock: true), $consent['generation'] !== $this->autoPayGeneration($user, lock: true) => 'changed',
                    !$this->autoPayConsentCardSaved($user, $consent) => 'failed',
                    default => 'enrolled',
                };
                if ($state === 'enrolled') $this->enableAutoPay($user, (int) $consent['agreement_id'], (int) $consent['accepted_at'], $consent['version']);
                if ($state === 'failed') $this->queueAutoPayConsentNotices($user, $consent, true);
                $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode(
                    array_replace($consent, ['state' => $state]), JSON_THROW_ON_ERROR)]);
            }));
        } catch (\Throwable $e) {
            $this->deferAutoPayConsent($userId, $key, $peek['id'], $e);
        }
    }

    /** The consent's card is still a saved card of this gateway. A removal in progress is retried, not final. */
    private function autoPayConsentCardSaved(User $user, array $consent): bool
    {
        $gateway = Gateway::where('extension', 'AeStripe')->first();
        $agreement = $gateway ? BillingAgreement::where('id', $consent['agreement_id'])->where('user_id', $user->id)
            ->where('gateway_id', $gateway->id)->first() : null;
        if (!$agreement || !empty($agreement->deleted_at)) return false;
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        return !($this->savedMethodIndex($user, $agreement->external_reference, $customer, $gateway)['removed'] ?? false);
    }

    /**
     * A failed enrollment try: deadlocks, lock timeouts and busy locks pass, so the consent stays paid and the sweep
     * tries again after a backoff that doubles from one minute up to an hour. After ten tries or an hour, staff get
     * one notice; the retries continue. If this record cannot be written either, the next pass tries again.
     */
    private function deferAutoPayConsent(int $userId, string $key, string $consentId, \Throwable $error): void
    {
        Log::warning('AeStripe Auto Pay after payment will retry', ['user_id' => $userId, 'error' => get_class($error)]);
        $first = false;
        try {
            ExtensionLocks::transaction('user:' . $userId, function () use ($userId, $key, $consentId, &$first) {
                $user = User::where('id', $userId)->firstOrFail();
                $consent = json_decode($this->lockedUserProperty($user, $key)?->value ?? 'null', true);
                if (($consent['state'] ?? null) !== 'paid' || $consent['id'] !== $consentId) return;
                $now = time();
                $tries = ($consent['tries'] ?? 0) + 1;
                $since = $consent['failing_since'] ?? $now;
                $first = $tries === 1;
                $notice = empty($consent['staff_notice']) && ($tries >= 10 || $now - $since >= 3600);
                if ($notice) $this->queueAutoPayConsentNotices($user, $consent, false);
                $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode(array_replace($consent, [
                    'tries' => $tries, 'failing_since' => $since, 'next_at' => $now + min(60 * 2 ** min($tries - 1, 6), 3600),
                ], $notice ? ['staff_notice' => true] : []), JSON_THROW_ON_ERROR)]);
            });
        } catch (\Throwable $ignored) {
            // The paid record is unchanged, so the next pass tries again.
        }
        if ($first && !$error instanceof \Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException) report($error);
    }

    /**
     * Notices for a paid consent that did not turn Auto Pay on. Failed: one email to the customer and one staff
     * notice. Still retrying: one staff notice. Each has a fixed source, so a repeat queues nothing new.
     */
    private function queueAutoPayConsentNotices(User $user, array $consent, bool $failed): void
    {
        $invoice = Invoice::where('id', $consent['invoice_id'])->first();
        $label = $invoice ? $this->invoiceLabel($invoice) : '#' . $consent['invoice_id'];
        $identities = [];
        if ($failed) {
            $identities[] = $identity = ['kind' => 'ae_stripe_auto_pay_mail:failed', 'source' => 'consent_' . $consent['id']];
            $this->storePaymentNotices($identity, [[
                'user_id' => (int) $user->id, 'title' => __('Auto Pay is not on'),
                'body' => __('Your payment for invoice :invoice went through, but Auto Pay did not turn on because the card is no longer saved. To turn on Auto Pay, add a card in Payment methods.',
                    ['invoice' => $label]),
                'url' => route('account.payment-methods'), 'link_label' => __('Turn on Auto Pay'),
            ]]);
        }
        $identities[] = $identity = ['kind' => 'ae_stripe_auto_pay_consent:' . ($failed ? 'failed' : 'retrying'), 'source' => 'consent_' . $consent['id']];
        $customer = 'customer #' . $user->id . ($user->email ? ' (' . $user->email . ')' : '');
        $this->storePaymentNotices($identity, $this->staffPaymentNotices('invoice', [
            'title' => $failed ? 'Auto Pay did not turn on after payment' : 'Auto Pay after payment keeps failing',
            'body' => 'Invoice ' . $label . ' was paid by ' . $customer . ' with Pay and turn on Auto Pay. ' . ($failed
                ? 'Auto Pay did not turn on because the card is no longer saved. The customer was emailed.'
                : 'Turning on Auto Pay keeps failing and is still retrying. Check the logs for "AeStripe Auto Pay after payment will retry".'),
            'url' => $invoice ? $this->adminInvoiceUrl($invoice) : url('/admin'),
        ]));
        DB::afterCommit(function () use ($identities) {
            foreach ($identities as $identity) foreach (DB::table('ae_stripe_outbox')->where('source', $identity['source'])
                ->whereIn('kind', [$identity['kind'] . ':staff', $identity['kind'] . ':customer'])->get() as $row) $this->deliverPaymentNotices($row->id);
        });
    }

    /**
     * The scheduled pass for paid consents whose enrollment did not finish: a busy saved-method lock, a database
     * error, or a process lost between settlement and enrollment. A consent waits out its backoff. It only runs
     * enrollPaidAutoPayConsent(), with its checks under the locks, so it never charges, a repeat changes nothing,
     * and each email's source keeps it to one.
     */
    public function retryAutoPayConsents(): void
    {
        foreach (Property::where('key', 'like', self::AUTO_PAY_CONSENT_KEY . '%')->where('value', 'like', '%"state":"paid"%')
            ->orderBy('updated_at')->limit(100)->get() as $property) {
            // The enrollment reads only this user's own record under its lock.
            if (!preg_match('/^' . self::AUTO_PAY_CONSENT_KEY . '[0-9]+$/', $property->key)) continue;
            if ((json_decode($property->value, true)['next_at'] ?? 0) > time()) continue;
            try { $this->enrollPaidAutoPayConsent((int) $property->model_id, $property->key); } catch (\Throwable $e) { report($e); }
        }
    }

    /** After recordIntentPayment() committed a success: turn on a consent that payment made paid. */
    private function enrollAfterPayment($intent): void
    {
        $invoice = Invoice::where('id', $intent->metadata->invoice_id ?? null)->first();
        if ($invoice) $this->enrollPaidAutoPayConsent((int) $invoice->user_id, self::AUTO_PAY_CONSENT_KEY . $invoice->id, intentId: $intent->id);
    }


    /**
     * Every turn-on sends one email that confirms it: the card, what is charged and how to stop. It joins the turn-on's
     * transaction, so a rolled-back turn-on sends nothing, and delivery retries from the outbox.
     */
    private function queueAutoPayOnMail(User $user, BillingAgreement $agreement, int $generation): void
    {
        // One source per Off-to-On transition, so the outbox keeps one email even if the turn-on is retried.
        $identity = ['kind' => 'ae_stripe_auto_pay_mail:on', 'source' => 'user_' . $user->id . '_on_' . $generation];
        $this->storePaymentNotices($identity, [[
            'user_id' => (int) $user->id, 'title' => __('Auto Pay is on'),
            'body' => __('Auto Pay is on. We charge :card for every renewal invoice. To stop, turn off Auto Pay in Payment methods.',
                ['card' => $this->autoPayCardLabel($agreement)]),
            'url' => route('account.payment-methods'), 'link_label' => __('Open Payment methods'),
        ]]);
        DB::afterCommit(function () use ($identity) {
            foreach (DB::table('ae_stripe_outbox')->where('source', $identity['source'])
                ->where('kind', $identity['kind'] . ':customer')->get() as $row) $this->deliverPaymentNotices($row->id);
        });
    }

    /** The user's services under lock, without Stripe subscriptions. Callers hold the user row lock. */
    private function autoPayServices(User $user): array
    {
        $services = [];
        foreach (Service::where('user_id', $user->id)->get() as $service) {
            if ($service->user_id == $user->id && !$service->subscription_id) $services[] = $service;
        }
        return $services;
    }

    /**
     * The switch is the customer's latest word, so a renewal retry waiting on an open invoice stops and that invoice is
     * paid by hand. Attempts live on the locked user row; an in-flight charge is left to finish.
     */
    private function stopWaitingRenewals(User $user): void
    {
        foreach ($this->lockedUserProperties($user, 'ae_stripe_checkout_') as $row) {
            $attempt = json_decode((string) $row->value, true);
            if (!is_array($attempt) || ($attempt['renewal']['state'] ?? null) !== 'waiting') continue;
            $attempt['renewal']['state'] = 'stopped';
            $user->properties()->updateOrCreate(['key' => $row->key], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
        }
    }

    /** A removed card turns account Auto Pay off when it was the account card, and unlinks the services it paid. */
    private function autoPayCardRemoved(User $user, int $agreementId): void
    {
        $value = $user->properties()->where('key', self::AUTO_PAY_PROPERTY)->first()?->value;
        $record = $value ? json_decode($value, true) : null;
        if (is_array($record) && ($record['agreement_id'] ?? null) === $agreementId) {
            $this->nextAutoPayGeneration($user);
            $user->properties()->where('key', self::AUTO_PAY_PROPERTY)->delete();
        }
        $choice = $user->properties()->where('key', self::AUTO_PAY_CARD)->first()?->value;
        if ($choice && (json_decode($choice, true)['agreement_id'] ?? null) === $agreementId) {
            $user->properties()->where('key', self::AUTO_PAY_CARD)->delete();
        }
        foreach ($this->autoPayServices($user) as $service) {
            if ($service->billing_agreement_id == $agreementId) $service->update(['billing_agreement_id' => null]);
        }
    }

    /** Local read-only dates for account, service and invoice opt-ins. No provider calls. */
    public function autoPayDates(Service $service): array
    {
        $dates = ['service_id' => (int) $service->id, 'invoice_on' => null, 'charge_on' => null,
            'charge_timing' => 'invoice_creation', 'state' => 'not_scheduled'];
        if (($service->plan?->type ?? null) !== 'recurring' || $service->status !== 'active'
            || $service->cancellation || $service->price <= 0) return $dates;
        $dates['state'] = 'missing';
        $pending = $service->invoices()->where('status', 'pending')->first();
        if ($pending) return array_replace($dates, ['state' => 'existing_invoice',
            'invoice_on' => $pending->created_at?->format('Y-m-d'), 'invoice_id' => (int) $pending->id]);
        if ($service->product?->server?->extension === 'Domains') {
            // Use Domains' calendar, never the hosting cron offset or a watched hosting domain.
            $presenter = \Paymenter\Extensions\Servers\Domains\Services\NativePresenter::class;
            if (!class_exists($presenter) || !app()->bound($presenter)) return $dates;
            $adapter = app($presenter);
            $domain = $adapter->service($service->user, (int) $service->id);
            if (!$domain) return $dates;
            if (isset($domain->renewNow['invoice'])) return array_replace($dates, ['state' => 'existing_invoice',
                'invoice_url' => $domain->renewNow['invoice']]);
            if ($domain->renewal !== 'On') return array_replace($dates, ['state' => $domain->renewal === 'Off' ? 'not_scheduled' : 'missing']);
            // The owner record carries the saved calendar's next issue date. The full summary builds the whole domain
            // card, prices included, and is slow over a long list, so it is read only when the record has no date
            // (a past issue date, or a Domains release without renewalSchedule).
            $issue = $domain->renewalSchedule['issue'] ?? null;
            if (!$issue) {
                $summary = $adapter->domainSummaries($service->user, [(int) $service->id])[$service->id] ?? null;
                $issue = $summary?->renewalIssueAt;
            }
        } else {
            $expiry = $service->expires_at;
            $issue = $expiry ? \DateTimeImmutable::createFromInterface($expiry)
                ->modify(sprintf('%+d days', -(int) config('settings.cronjob_invoice', 7)))->format('Y-m-d') : null;
        }
        if (!$issue) return $dates;
        // An overdue calendar date is not a promise to charge in the past.
        $today = now()->format('Y-m-d');
        return array_replace($dates, ['invoice_on' => $issue, 'charge_on' => $issue > $today ? $issue : null,
            'state' => $issue <= $today ? 'next_billing_run' : 'scheduled']);
    }

    /**
     * Whether Auto Pay will pay this open invoice: null while Auto Pay is off (nothing to say), true while a
     * renewal charge or retry is still planned for it, false otherwise. Auto Pay charges renewal invoices when they
     * are issued, so a new order's first invoice, or a renewal whose retries stopped, is paid by hand. Read only.
     */
    public function autoPayCoversInvoice(Invoice $invoice): ?bool
    {
        $user = $invoice->user;
        if (!$user || !$this->autoPayOn($user)) return null;
        $value = $user->properties()->where('key', 'ae_stripe_checkout_' . $invoice->id)->first()?->value;
        $attempt = $value ? json_decode((string) $value, true) : null;
        return in_array($attempt['renewal']['state'] ?? null, ['active', 'waiting'], true);
    }

    public function invoiceAutoPayDates(Invoice $invoice): array
    {
        $dates = [];
        foreach ($invoice->items as $item) {
            if ($item->reference_type !== (new Service)->getMorphClass()) continue;
            $service = $item->reference;
            if ($service && $service->user_id == $invoice->user_id && ($service->plan?->type ?? null) === 'recurring') {
                $dates[$service->id] = $this->autoPayDates($service);
            }
        }
        return $dates;
    }
}
