<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use Paymenter\Extensions\Gateways\AeStripe\ExtensionLocks;

use App\Helpers\ExtensionHelper;
use App\Exceptions\DisplayException;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use App\Models\Audit;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/ReceiptReturns.php';

trait Checkout
{
    use ReceiptReturns;

    /**
     * Settlement reads the paid balance under the user and invoice row locks and locks only the payment rows it found
     * (lockedTransactions), so it holds no index gap that a neighbouring invoice's payment waits on. A transaction staff
     * add in the admin panel takes no invoice lock, so a settlement at that moment may not count it. Core code can still
     * make this transaction a deadlock victim; MariaDB rolls all of it back, so it runs again from the settled check.
     * Pay and the other payment lock transactions (withPaymentLock) use the same count: a rerun locks the user and invoice
     * again and rereads the attempt and balance. Laravel reruns only the outermost transaction. A Stripe cancel or amount
     * update inside a payment lock is its last statement, so a rerun follows only a victim before it was sent. The
     * confirmation never reruns once it sent its update; a later victim records Stripe's answer again (confirmAttempt).
     */
    private const SETTLEMENT_ATTEMPTS = 3;

    public function pay($invoice, $total)
    {
        abort_unless($this->gatewayEnabled(), 409);
        abort_unless(Auth::id() == $invoice->user_id, 403);
        // Do not fall back to an anonymous intent: saved methods require this customer.
        try {
            $customer = $this->createOrGetStripeCustomer($invoice->user, (bool) $this->config('stripe_create_customers'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AeStripe customer setup failed', [
                'invoice_id' => $invoice->id, 'error' => get_class($e),
            ]);
            throw new DisplayException('Could not prepare your payment. Try again.');
        }
        $intentData = $this->checkoutPaymentData($invoice, $this->toMinorUnits($total, $invoice->currency_code), $customer->id);
        try {
            $intent = $this->checkoutIntent($invoice, $intentData, elements: true);
        } catch (\Throwable $e) {
            // Every attempt was a deadlock victim and was rolled back. Opening Pay never charges, so a new try is safe.
            if (!$this->lockConflict($e)) throw $e;
            \Illuminate\Support\Facades\Log::warning('AeStripe Pay lock conflict', ['invoice_id' => $invoice->id]);
            throw new DisplayException('Could not prepare your payment. Try again.');
        }
        if ($intent->status === 'succeeded') {
            $this->recordIntentPayment($intent, 'succeeded');
            if (!InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists()) {
                $this->paymentReconcileMessage();
            }
            return view('gateways.ae-stripe::payment-status', ['message' => 'Payment received. Refresh the invoice to check its status.']);
        }
        if (in_array($intent->status, ['processing', 'requires_capture'], true)) {
            return view('gateways.ae-stripe::payment-status', ['message' => 'Payment pending. Refresh the invoice to check its status.']);
        }
        // API assumptions must be verified against the pinned Basil version in a sandbox.
        // https://docs.stripe.com/api/customer_sessions/create
        // Removal is disabled: native agreements may still reference a removed method.
        // Redisplay is disabled: the theme lists saved methods beside this form, so Stripe shows only a new one.
        $session = null;
        try {
            $session = $this->request('post', '/customer_sessions', [
                'customer' => $customer->id,
                'components' => ['payment_element' => [
                    'enabled' => 'true',
                    'features' => [
                        'payment_method_redisplay' => 'disabled',
                        'payment_method_save' => 'disabled',
                        'payment_method_remove' => 'disabled',
                    ],
                ]],
            ]);
        } catch (RequestException | ConnectionException $e) {
            \Illuminate\Support\Facades\Log::warning('AeStripe customer session unavailable', [
                'invoice_id' => $invoice->id, 'http_status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);
        }

        return view('gateways.ae-stripe::pay', [
            'invoice' => $invoice, 'total' => $total, 'intent' => $intent,
            'paymentAmount' => $this->paymentAmountLabel($intent, $invoice),
            'autoPayDates' => $this->invoiceAutoPayDates($invoice),
            'customerSessionSecret' => $session?->client_secret,
            'stripePublishableKey' => $this->config('stripe_publishable_key'),
            'linkDisplay' => $this->stripeLinkDisplay(),
            'businessName' => $this->stripeBusinessName(),
            'autoPay' => $this->autoPayOffered($invoice->user) ? $this->autoPayConsent() : null,
        ]);
    }

    // Paymenter's Company Name; null lets Stripe fall back to the account name.
    private function stripeBusinessName(): ?string
    {
        $name = trim((string) config('settings.company_name', ''));
        return $name === '' ? null : $name;
    }

    private function stripeLinkDisplay(): string
    {
        $value = $this->config('stripe_link_display');
        return in_array($value, ['above_card', 'inside_card', 'stripe_default'], true) ? $value : 'above_card';
    }

    private function checkoutPaymentData($invoice, int $amount, string $customer): array
    {
        return [
            'description' => __('invoices.payment_for_invoice', ['number' => $invoice->number ?? $invoice->id]),
            'amount' => $amount, 'currency' => $invoice->currency_code,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1'],
            'customer' => $customer,
        ];
    }

    private function gatewayEnabled(): bool
    {
        return (bool) \App\Models\Gateway::where('extension', 'AeStripe')->first()?->enabled;
    }

    public function consent(Request $request)
    {
        abort_unless($this->gatewayEnabled(), 409);
        $request->validate([
            'intent' => 'required|string|regex:/^pi_[a-zA-Z0-9]+$/|max:255',
            'save' => 'required|boolean',
        ]);
        $intent = $this->request('get', '/payment_intents/' . $request->input('intent'));
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id ?? null);
        $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
        abort_unless(Auth::id() == $invoice->user_id && $customer && $intent->customer === $customer
            && ($intent->metadata->ae_stripe_gateway ?? null) === '1', 403);
        $elements = $this->withPaymentLock($invoice, fn ($user, $current) => $this->isElementsAttempt($this->readPaymentAttempt($user, $current) ?? [], $intent));
        if (($intent->confirmation_method ?? 'automatic') !== 'manual' && !$elements) {
            // An already-open legacy tab must not confirm outside the invoice lock.
            $this->sendPaymentAction($invoice, $intent, 'cancel');
            return response()->json(['message' => 'The payment form changed. Refresh the invoice before paying.'], 409);
        }
        $save = $request->boolean('save');
        if ($intent->status === 'requires_action') {
            // Resume 3DS without changing the consent already used for confirmation.
            abort_unless($save === (($intent->metadata->ae_stripe_consent ?? null) === '1'), 409);
            return response()->json(['ok' => true]);
        }
        abort_unless(in_array($intent->status, ['requires_payment_method', 'requires_confirmation']), 409);
        // Clear legacy top-level usage; consent applies only to card and Link.
        $this->request('post', '/payment_intents/' . $intent->id, [
            'setup_future_usage' => '',
            'payment_method_options' => [
                'card' => ['setup_future_usage' => $save ? 'off_session' : 'none'],
                'link' => ['setup_future_usage' => $save ? 'off_session' : 'none'],
            ],
            'metadata' => ['ae_stripe_consent' => $save ? '1' : '0',
                'ae_stripe_consent_at' => $save ? (string) time() : ''],
        ]);

        return response()->json(['ok' => true]);
    }

    public function confirmPayment(Request $request)
    {
        abort_unless($this->gatewayEnabled(), 409);
        $request->validate([
            'intent' => 'required|string|regex:/^pi_[a-zA-Z0-9]+$/|max:255',
            'confirmation_token' => 'nullable|string|regex:/^ctoken_[a-zA-Z0-9]+$/|max:255',
            'amount' => 'required|integer|min:1', 'save' => 'required|boolean', 'auto_pay' => 'sometimes|boolean',
            'saved_notice' => 'nullable|string|regex:/^[a-f0-9]{32}$/',
        ]);
        $autoPay = $request->boolean('auto_pay');
        try {
            $intent = $this->request('get', '/payment_intents/' . $request->input('intent'));
            $invoice = Invoice::findOrFail($intent->metadata->invoice_id ?? null);
            $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
            abort_unless(Auth::id() == $invoice->user_id && $customer && $intent->customer === $customer
                && ($intent->metadata->ae_stripe_gateway ?? null) === '1', 403);
            $acceptedCurrency = strtolower($intent->currency);
            // The open Elements form can submit a new token after a definite decline. The
            // retired intent identifies the invoice, while checkoutIntent serializes its replacement.
            if ($request->input('confirmation_token') && in_array($intent->status, ['requires_payment_method', 'canceled'], true)
                && ($intent->amount_received ?? 0) === 0
                && $this->hasFailedPayment($invoice, $intent->id)) {
                $intent = $this->checkoutIntent($invoice, $this->checkoutPaymentData($invoice, (int) $request->input('amount'), $customer), elements: true);
            }
            // A bank check that failed or was cancelled in Stripe.js leaves a definite zero-money refusal: record it
            // now, so the dialog and the invoice say the same thing, and the next card starts a new attempt. The
            // failure webhook can record and retire it before the open dialog asks; the dialog still gets the decline.
            $retired = $intent->status === 'canceled' && ($intent->amount_received ?? 0) === 0
                && $this->hasFailedPayment($invoice, $intent->id);
            $refusal = !$request->input('confirmation_token') && ($intent->amount_received ?? 0) === 0
                && ($retired || $intent->status === 'requires_payment_method' && !empty($intent->last_payment_error))
                ? ($intent->last_payment_error ?? (object) []) : null;
            if ($refusal && !$retired) $this->recordIntentPayment($intent, 'failed');
            $intent = $this->retireFailedElementsPayment($invoice, $intent);
            if ($refusal) throw new \Paymenter\Extensions\Gateways\AeStripe\PaymentDeclinedException(
                $this->paymentFailureReason($refusal->code ?? null, $refusal->decline_code ?? null));
            if ($intent->status === 'canceled') throw new DisplayException('This payment was not completed. Refresh the invoice to try another payment.');
            $agreement = null;
            $noticeToken = $request->input('saved_notice');
            $params = ['return_url' => route('extensions.gateways.ae-stripe.payment-return', ['invoice' => $invoice->id, 'saved_notice' => $noticeToken]), 'use_stripe_sdk' => 'true'];
            if ($request->input('confirmation_token')) {
                // Auto Pay charges a saved card, and is offered only while it is off; checked before any charge.
                if ($autoPay && !$request->boolean('save')) throw new DisplayException('Save this card to turn on Auto Pay.');
                if ($autoPay && !$this->autoPayOffered($invoice->user)) throw new DisplayException('Auto Pay changed. Refresh the invoice before paying.');
                $params += ['confirmation_token' => $request->input('confirmation_token'), 'setup_future_usage' => '',
                    'payment_method_options' => ['card' => ['setup_future_usage' => $request->boolean('save') ? 'off_session' : 'none'],
                        'link' => ['setup_future_usage' => $request->boolean('save') ? 'off_session' : 'none']],
                    // The words shown are recorded now; Auto Pay turns on only once this payment succeeds.
                    'metadata' => ['ae_stripe_consent' => $request->boolean('save') ? '1' : '0',
                        'ae_stripe_consent_at' => $request->boolean('save') ? (string) time() : '',
                        'ae_stripe_consent_version' => $request->boolean('save') ? self::SAVE_CONSENT : '',
                    ] + $this->autoPayIntentConsent($invoice->user, $autoPay)];
            } else {
                abort_unless(in_array($intent->status, ['requires_action', 'requires_confirmation', 'processing', 'succeeded'], true), 409);
                abort_unless($request->boolean('save') === (($intent->metadata->ae_stripe_consent ?? null) === '1'), 409);
                abort_unless($autoPay === (($intent->metadata->ae_stripe_auto_pay ?? '') !== ''), 409);
                $agreement = $this->paymentResumeAgreement($invoice);
                if ($agreement) $params['payment_method'] = $agreement->external_reference;
            }
            // Automatic authentication completes in Stripe.js. Only native manual payments reconfirm.
            if ($request->input('confirmation_token') || $intent->status === 'requires_confirmation' && ($intent->confirmation_method ?? null) === 'manual') {
                $intent = $this->confirmAttempt($invoice, $intent->id, $params, $agreement, expectedAmount: (int) $request->input('amount'), expectedCurrency: $acceptedCurrency);
            }
            if ($intent->status === 'succeeded') {
                $this->recordIntentPayment($intent, 'succeeded');
                $noticeToken = $this->saveConsentedMethod($intent, $noticeToken);
            }
            return response()->json(['intent' => $intent->id, 'status' => $intent->status, 'saved_notice' => $noticeToken,
                'client_secret' => $intent->status === 'requires_action' ? $intent->client_secret : null]);
        } catch (DisplayException $e) {
            return response()->json(['message' => $e->getMessage(), 'intent' => $intent->id ?? null,
                'declined' => $e instanceof \Paymenter\Extensions\Gateways\AeStripe\PaymentDeclinedException], 409);
        } catch (RequestException | ConnectionException $e) {
            return response()->json(['message' => 'Stripe is unavailable. We will check this payment automatically.'], 503);
        }
    }

    public function finishPayment(Request $request)
    {
        $noticeToken = $request->input('saved_notice');
        if (!is_string($noticeToken) || !preg_match('/\A[a-f0-9]{32}\z/', $noticeToken)) $noticeToken = null;
        // Keep a local invoice link even when Stripe cannot be reached on the bank return.
        $invoice = null;
        if ($request->input('invoice')) {
            $invoice = Invoice::findOrFail($request->input('invoice'));
            abort_unless(Auth::id() == $invoice->user_id, 403);
        }
        try {
            if (!$this->gatewayEnabled()) throw new DisplayException('Payments are paused. Please check back later.');
            $id = $request->input('payment_intent');
            if (!is_string($id) || !preg_match('/^pi_[a-zA-Z0-9]+$/', $id)) throw new DisplayException('Could not check this payment. Open the invoice to check its status.');
            $intent = $this->request('get', '/payment_intents/' . $id);
            $owner = Invoice::findOrFail($intent->metadata->invoice_id ?? null);
            abort_unless(Auth::id() == $owner->user_id && (!$invoice || $invoice->id === $owner->id)
                && $intent->customer === $owner->user->properties()->where('key', 'stripe_id')->first()?->value
                && ($intent->metadata->ae_stripe_gateway ?? null) === '1', 403);
            $invoice = $owner;
            $secret = $request->input('payment_intent_client_secret');
            if (!is_string($secret) || !is_string($intent->client_secret ?? null) || !hash_equals($intent->client_secret, $secret)) {
                // A link without Stripe's redirect binding may read status, but cannot change it.
                throw new DisplayException(match ($intent->status) {
                    'succeeded' => 'Payment received. Open the invoice to check its status.',
                    'requires_action', 'requires_confirmation' => 'This payment is not finished. Open the invoice to continue.',
                    'requires_payment_method', 'canceled' => 'This payment was not completed. Choose another payment method on the invoice.',
                    default => 'Payment pending. Open the invoice to check its status.',
                });
            }
            $intent = $this->retireFailedElementsPayment($invoice, $intent);
            if ($intent->status === 'requires_confirmation') {
                $amount = $this->withPaymentLock($invoice, function ($user, $current) {
                    $attempt = $this->readPaymentAttempt($user, $current);
                    return $attempt['checkout_amount'] ?? $attempt['confirmation']['amount'] ?? null;
                });
                if ($amount === null) throw new DisplayException('Refresh the invoice before finishing this payment.');
                $agreement = $this->paymentResumeAgreement($invoice);
                $params = ['return_url' => route('extensions.gateways.ae-stripe.payment-return', ['invoice' => $invoice->id, 'saved_notice' => $noticeToken]), 'use_stripe_sdk' => 'true'];
                if ($agreement) $params['payment_method'] = $agreement->external_reference;
                $intent = $this->confirmAttempt($invoice, $id, $params, $agreement, expectedAmount: $amount);
            }
            if ($intent->status === 'succeeded') {
                $this->recordIntentPayment($intent, 'succeeded');
                $noticeToken = $this->saveConsentedMethod($intent, $noticeToken);
            }
            if (in_array($intent->status, ['requires_payment_method', 'canceled'], true)) throw new DisplayException('This payment was not completed. Choose another payment method on the invoice.');
            if ($intent->status === 'requires_action') throw new DisplayException('This payment still needs authentication. Open the invoice to finish it.');
            return redirect()->to($this->savedMethodDestination($invoice, $noticeToken));
        } catch (DisplayException $e) {
            $message = $e->getMessage();
        } catch (RequestException | ConnectionException $e) {
            $message = 'We are checking this payment automatically. Open the invoice for its status.';
        } catch (\Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) throw $e;
            report($e);
            $message = 'Could not finish checking this payment. Open the invoice to check its status.';
        }
        return view('gateways.ae-stripe::payment-return', ['message' => $message,
            'invoiceUrl' => $invoice ? route('invoices.show', $invoice) : route('invoices')]);
    }

    private function paymentResumeAgreement($invoice): ?BillingAgreement
    {
        $id = $this->withPaymentLock($invoice, fn ($user, $current) => $this->readPaymentAttempt($user, $current)['confirmation']['agreement'] ?? null);
        if (!$id) return null;
        $agreement = BillingAgreement::where('id', $id)->first();
        if (!$agreement) throw new DisplayException('This payment method was removed. Choose another payment method.');
        return $agreement;
    }

    private function recordIntentPayment($intent, $status, bool $prepareMethod = true, ?string $subscriptionInvoiceId = null)
    {
        $this->ensureReceiptReturnSchema();
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        $userId = $invoice->user_id;
        if ($prepareMethod) $intent = $this->prepareTransactionMethod($invoice, $intent, $status);
        ExtensionLocks::transaction('user:' . $userId, function () use ($intent, $status, $userId, $subscriptionInvoiceId) {
            $invoice = Invoice::where('id', $intent->metadata->invoice_id)->lockForUpdate()->firstOrFail();
            $user = User::where('id', $userId)->firstOrFail();
            $this->bindWebhookAttempt($user, $invoice, $intent);
            if ($this->lockedTransactions(InvoiceTransaction::where('transaction_id', $intent->id), 'succeeded')) {
                $this->schedulePaymentReviewAlert($invoice, $intent->id);
                DB::afterCommit(fn () => $this->processReceiptReturns($invoice, $intent->id));
                return;
            }
            if ($this->paymentManuallyAccounted($invoice, $intent->id)) return;
            if (!empty($intent->customer) && $intent->customer !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment customer mismatch', ['invoice_id' => $invoice->id]);
                if ($status === 'succeeded') $this->recordPaymentReview($invoice, $intent, 'customer mismatch');
                return;
            }
            if (strtolower($invoice->currency_code) !== strtolower($intent->currency ?? '')) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment currency mismatch', ['invoice_id' => $invoice->id]);
                if ($status === 'succeeded') $this->recordPaymentReview($invoice, $intent, 'currency mismatch');
                return;
            }
            if ($status === 'succeeded' && !\Paymenter\Extensions\Gateways\AeStripe\SettlementStore::claim($intent->id, (int) $invoice->id)) {
                DB::afterCommit(fn () => $this->processReceiptReturns($invoice, $intent->id));
                return;
            }
            if ($this->readReceipt($user, $intent->id)['closed_invoice'] ?? false) {
                // A closed receipt remains wholly reserved even if the invoice is reopened.
                DB::afterCommit(fn () => $this->processReceiptReturns($invoice, $intent->id));
                return;
            }
            if ($status === 'succeeded' && !in_array($invoice->status, ['pending', 'paid'], true)) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment received for nonpending invoice', ['invoice_id' => $invoice->id]);
                $this->recordReceipt($invoice, $intent, $intent->amount_received, closed: true, subscriptionInvoiceId: $subscriptionInvoiceId);
                return;
            }
            if ($status === 'succeeded' && ($invoice->status === 'paid' || $intent->amount_received > $this->paymentBalance($invoice))) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment received for paid invoice', ['invoice_id' => $invoice->id]);
            }
            if ($status === 'succeeded') $this->recordReceipt($invoice, $intent,
                max(0, $intent->amount_received - ($invoice->status === 'paid' ? 0 : max(0, $this->paymentBalance($invoice)))));
            $helper = match ($status) {
                'processing' => 'addProcessingPayment', 'failed' => 'addFailedPayment', default => 'addPayment',
            };
            $amount = $status === 'succeeded' ? $intent->amount_received : $intent->amount;
            $this->settleAutoPayConsent($user, $invoice, $intent, $status);
            if ($status === 'failed') {
                $failedId = $this->failedPaymentId($intent, $this->readPaymentAttempt($user, $invoice) ?? []);
                foreach ($this->lockedTransactions(InvoiceTransaction::where('transaction_id', $intent->id), 'processing') as $pending) {
                    InvoiceTransaction::whereKey($pending->id)->update(['transaction_id' => $failedId]);
                }
            }
            $transaction = ExtensionHelper::{$helper}($invoice, 'AeStripe', $this->fromMinorUnits($amount, $intent->currency), null,
                $status === 'failed' ? $this->failedPaymentId($intent, $this->readPaymentAttempt($user, $invoice) ?? []) : $intent->id);
            $this->recordTransactionMethod($transaction, $intent, $status === 'succeeded', $status === 'failed');
            if ($status === 'succeeded') {
                $attempt = $this->readPaymentAttempt($user, $invoice);
                if (($attempt['intent'] ?? null) === $intent->id) {
                    if ($this->paymentBalance($invoice) <= 0) $user->properties()->where('key', 'ae_stripe_checkout_' . $invoice->id)->delete();
                    else $this->writePaymentAttempt($user, $invoice, ['version' => 2, 'key' => $attempt['key'],
                        'created' => $attempt['created'], 'intent' => $intent->id, 'retry' => false]);
                }
            }
            if (in_array($status, ['processing', 'failed'], true)) {
                $attempt = $this->readPaymentAttempt($user, $invoice);
                if (($attempt['intent'] ?? null) === $intent->id) {
                    if ($status === 'failed') $attempt['latest_charge'] = $intent->latest_charge ?? null;
                    if ($status === 'failed') $attempt = $this->renewalRefused($invoice, $attempt, $intent);
                    $this->writePaymentAttempt($user, $invoice,
                        array_replace($attempt, ['agreement_id' => null, 'confirmation' => null, 'retry' => $status === 'processing', 'funds_pending' => $status === 'processing' || ($intent->amount_received ?? 0) > 0]));
                }
            }
        }, self::SETTLEMENT_ATTEMPTS);
        if ($status === 'succeeded') $this->enrollAfterPayment($intent);
    }

    private function recordPaymentReview($invoice, $intent, string $reason): void
    {
        // The caller holds the user and invoice locks. Keep one staff record per invoice and PaymentIntent.
        if (in_array($reason, ['customer mismatch', 'currency mismatch', 'invoice not pending'], true)) {
            $attempt = $this->readPaymentAttempt($invoice->user, $invoice);
            if (($attempt['intent'] ?? null) === $intent->id) $this->writePaymentAttempt($invoice->user, $invoice,
                array_replace($attempt, ['state' => 'reconcile', 'reason' => $reason, 'retry' => true, 'alert_sent' => true, 'funds_pending' => true]));
        }
        if (!$this->invoiceAuditExists($invoice, 'ae_stripe_payment_review', ['payment_intent_id' => $intent->id])) Audit::create([
            'event' => 'ae_stripe_payment_review',
            'auditable_type' => $invoice->getMorphClass(), 'auditable_id' => $invoice->id,
            'old_values' => [],
            'new_values' => [
                'payment_intent_id' => $intent->id,
                'amount_minor' => $intent->amount_received,
                'currency' => $intent->currency ?? '', 'reason' => $reason,
                'resolution' => 'Check Stripe and choose a refund or account credit; this needs your decision.',
            ],
        ]);
        $key = 'ae_stripe_payment_alert_' . $invoice->id . '_' . $intent->id;
        if (!$this->lockedUserProperty($invoice->user, $key)) {
            $invoice->user->properties()->updateOrCreate(['key' => $key],
                ['value' => json_encode(['reason' => $reason, 'sent' => false], JSON_THROW_ON_ERROR)]);
        }
        $this->schedulePaymentReviewAlert($invoice, $intent->id);
    }

    // Writers hold the owner lock before their first read. A plain read sees their records without locking
    // the shared index gap where neighbouring invoices insert their audits.
    private function invoiceAuditExists($invoice, string $event, array $values): bool
    {
        $query = Audit::where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)->where('event', $event);
        foreach ($values as $field => $value) $query = $query->where('new_values->' . $field, $value);
        return (bool) $query->first();
    }

    private function schedulePaymentReviewAlert($invoice, string $intentId): void
    {
        // The caller holds the owner lock. Existing sent markers also cover pre-upgrade delivery.
        $key = 'ae_stripe_payment_alert_' . $invoice->id . '_' . $intentId;
        $property = $invoice->user->properties()->where('key', $key)->first();
        if (!$property) return;
        $alert = json_decode($property->value, true, flags: JSON_THROW_ON_ERROR);
        if ($alert['sent']) return;
        $audit = Audit::where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
            ->where('event', 'ae_stripe_payment_review')->where('new_values->payment_intent_id', $intentId)->firstOrFail();
        $this->queuePaymentNotices($audit, $this->staffPaymentNotices('invoice', [
            'title' => 'Stripe payment needs review', 'url' => $this->adminInvoiceUrl($invoice),
            'body' => $this->invoiceNoticeBody('payment',
                'Payment on invoice ' . $this->invoiceLabel($invoice) . '.',
                'Invoice ' . $this->invoiceLabel($invoice) . ', payment ' . $intentId . ': ' . $alert['reason']
                    . '. Check Stripe and choose a refund or account credit; this needs your decision.'),
        ]));
        $alert['sent'] = true; // Delivery is now owned by the outbox, independently of this property.
        $invoice->user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($alert, JSON_THROW_ON_ERROR)]);
    }

    /**
     * The amount being authorised, in the store's own price style ("$120.00") when the charge is in the invoice
     * currency and that currency has a symbol, otherwise with its code ("120.00 USD").
     */
    private function paymentAmountLabel($intent, $invoice): string
    {
        $value = $this->fromMinorUnits($intent->amount, $intent->currency);
        $decimals = $this->currencyDecimals($intent->currency);
        $currency = strtolower((string) ($invoice->currency_code ?? '')) === strtolower((string) $intent->currency)
            ? ($invoice->currency ?? null) : null;
        if (!$currency || ((string) ($currency->prefix ?? '') === '' && (string) ($currency->suffix ?? '') === '')) {
            return number_format($value, $decimals, '.', '') . ' ' . strtoupper($intent->currency);
        }
        [$point, $thousands] = match ($currency->format ?? null) {
            '1.000,00' => [',', '.'], '1 000,00' => [',', ' '], '1 000.00' => ['.', ' '], default => ['.', ','],
        };
        return $currency->prefix . number_format($value, $decimals, $point, $thousands) . $currency->suffix;
    }

    private function savePaidMethod($intent, $recoveryNotice = null)
    {
        try { $saved = $this->savePaidMethodDetails($intent); }
        catch (DisplayException $e) {
            try { $this->discardSavedMethodNotices($intent); } catch (\Throwable $ignored) {
                if ($recoveryNotice) $this->savedNoticeAccessWarning('refusal cleanup unavailable', $recoveryNotice, 'local');
                else $this->noticeWarning('AeStripe notice cleanup will retry');
            }
            throw $e;
        }
        try {
            if ($saved === true || is_array($saved)) $this->completeSavedMethodNotices($intent, $saved);
            else $this->discardSavedMethodNotices($intent);
        } catch (\Throwable $e) {
            if ($recoveryNotice) $this->savedNoticeAccessWarning('notice completion unavailable', $recoveryNotice, 'local');
            else $this->noticeWarning('AeStripe saved method notice will retry');
        }
        if ($saved === true || is_array($saved)) $this->turnOnPaidAutoPay($intent);
        return $saved;
    }

    private function savePaidMethodDetails($intent)
    {
        if (($intent->metadata->ae_stripe_gateway ?? null) !== '1'
            || ($intent->metadata->ae_stripe_consent ?? null) !== '1') {
            return;
        }
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
        if (!$customer || ($intent->customer ?? null) !== $customer || empty($intent->payment_method)) {
            \Illuminate\Support\Facades\Log::warning('AeStripe saved method customer mismatch', ['invoice_id' => $invoice->id]);
            return;
        }
        $method = $this->request('get', '/payment_methods/' . $intent->payment_method);
        if (!in_array($method->type, ['card', 'link'])) {
            return; // The checkout opt-in authorizes saving a card or Link only.
        }
        if (($intent->payment_method_options->{$method->type}->setup_future_usage ?? null) !== 'off_session') return;
        // Payments confirmed before versions were recorded showed the v1 box.
        $version = $intent->metadata->ae_stripe_consent_version ?? null;
        $saved = $this->persistAgreement($invoice->user, $method, ['source' => $intent->id,
            'version' => in_array($version, self::SAVE_CONSENTS, true) ? $version : 'off_session_v1', 'accepted_at' => (int) ($intent->metadata->ae_stripe_consent_at ?? $intent->created ?? time()), 'user_id' => $invoice->user_id]);
        return $saved;
    }

    private function methodCustomerMatches($user, $method, bool $current = false): bool
    {
        $property = $user->properties()->where('key', 'stripe_id');
        $customer = ($current ? $property : $property)->first()?->value;
        if (!$customer || ($method->customer ?? null) !== $customer) {
            \Illuminate\Support\Facades\Log::warning('AeStripe saved method customer mismatch', ['user_id' => $user->id]);
            return false;
        }
        return true;
    }

}
