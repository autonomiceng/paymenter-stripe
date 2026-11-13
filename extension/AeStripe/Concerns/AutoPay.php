<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use Paymenter\Extensions\Gateways\AeStripe\ExtensionLocks;

use App\Models\{BillingAgreement, Gateway, Invoice, Service, User};
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
        return $this->gatewayEnabled() && $this->bundledInactive() && !$this->autoPayDefault($user);
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
     * "Auto Pay is on" email. The caller holds the user row lock.
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
        $record = ['version' => $version, 'user_id' => (int) $user->id,
            'accepted_at' => $acceptedAt ?? time(), 'agreement_id' => (int) $agreement->id];
        $user->properties()->updateOrCreate(['key' => self::AUTO_PAY_PROPERTY], ['value' => json_encode($record, JSON_THROW_ON_ERROR)]);
        $this->queueAutoPayOnMail($user, $agreement);
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
     * Turn on and pay, from the one consent in the Turn on dialog. The first ticked invoice is charged before anything
     * is turned on, so a card that declines leaves Auto Pay exactly as it was and the reason goes back to the dialog.
     * Once that payment succeeds or is processing, Auto Pay turns on with the card and the other invoices are paid as
     * Auto Pay payments, each under the gateway's Auto Pay card check. The caller checks that each invoice is the
     * customer's own, open, and at the amount the dialog showed.
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
        $pay = function (Invoice $invoice, string $amount, bool $autoPay) use ($agreement): array {
            try {
                $autoPay ? $this->chargeAutoPayAccepted($invoice, $amount, $agreement) : $this->chargeAccepted($invoice, $amount, $agreement);
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
        [$first, $amount] = $invoices[0];
        $paid = $pay($first, $amount, false);
        $result['invoices'][] = ['id' => $paid['id'], 'status' => $paid['status']];
        if (!in_array($paid['status'], ['paid', 'processing'], true)) {
            return ['message' => $paid['message'] ?? 'This payment was not completed.'] + $result;
        }
        try {
            $this->withSavedMethodBindingLock((int) $user->id, fn () => ExtensionLocks::transaction('user:' . $user->id, function () use ($user, $agreement) {
                $locked = User::where('id', $user->id)->firstOrFail();
                return $this->enableAutoPay($locked, (int) $agreement->id);
            }));
        } catch (\Throwable $e) {
            if (!$e instanceof \Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException
                && !$e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) report($e);
            return ['message' => 'The payment went through, but Auto Pay was not turned on. Try turning it on again.'] + $result;
        }
        $result['enabled'] = true;
        foreach (array_slice($invoices, 1) as [$invoice, $amount]) {
            // A later invoice that is not paid keeps its own reason.
            $result['invoices'][] = array_intersect_key($pay($invoice, $amount, true), array_flip(['id', 'status', 'message']));
        }
        return $result;
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
                if (!$this->autoPayDefault($user, lock: true)) {
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
     * Every turn-on sends one email that confirms it: the card, what is charged and how to stop. It joins the turn-on's
     * transaction, so a rolled-back turn-on sends nothing, and delivery retries from the outbox.
     */
    private function queueAutoPayOnMail(User $user, BillingAgreement $agreement): void
    {
        $identity = ['kind' => 'ae_stripe_auto_pay_mail:on', 'source' => 'user_' . $user->id . '_' . bin2hex(random_bytes(8))];
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
            $summary = $adapter->domainSummaries($service->user, [(int) $service->id])[$service->id] ?? null;
            $issue = $summary?->renewalIssueAt;
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
     * Whether Auto Pay will pay this open invoice: null while account Auto Pay is off (nothing to say), true while a
     * renewal charge or retry is still planned for it, false otherwise. Auto Pay charges renewal invoices when they
     * are issued, so a new order's first invoice, or a renewal whose retries stopped, is paid by hand. Read only.
     */
    public function autoPayCoversInvoice(Invoice $invoice): ?bool
    {
        $user = $invoice->user;
        if (!$user || !$this->autoPayDefault($user)) return null;
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
