<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\Amounts;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\Checkout;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\CardDuplicates;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\PaymentFees;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\CheckoutIntent;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\ChargeRecovery;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\SetupConsent;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\SetupIntent;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\Signature;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\SubscriptionAttempt;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\SubscriptionPayments;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\Webhooks;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\StripeMetadata;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\TransactionMethod;

require_once __DIR__ . '/GatewayLifecycle.php';
require_once __DIR__ . '/Concerns/StripeMetadata.php';
require_once __DIR__ . '/Concerns/Signature.php';
require_once __DIR__ . '/Concerns/Checkout.php';
require_once __DIR__ . '/Concerns/CardDuplicates.php';
require_once __DIR__ . '/Concerns/Amounts.php';
require_once __DIR__ . '/Concerns/SubscriptionAttempt.php';
require_once __DIR__ . '/Concerns/SubscriptionPayments.php';
require_once __DIR__ . '/Concerns/CheckoutIntent.php';
require_once __DIR__ . '/PaymentPendingException.php';
require_once __DIR__ . '/Concerns/ChargeRecovery.php';
require_once __DIR__ . '/Concerns/InvoicePayments.php';
require_once __DIR__ . '/Concerns/WebhookContinuity.php';
require_once __DIR__ . '/Concerns/SetupConsent.php';
require_once __DIR__ . '/Concerns/SetupIntent.php';
require_once __DIR__ . '/Concerns/Webhooks.php';
require_once __DIR__ . '/Concerns/PaymentFees.php';
require_once __DIR__ . '/Concerns/TransactionMethod.php';

#[ExtensionMeta(
    name: 'AE Stripe',
    description: 'Stripe payments with saved cards.',
    version: '0.1.1',
    author: 'Autonomic Engineering',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48cmVjdCB3aWR0aD0iNTEyIiBoZWlnaHQ9IjUxMiIgZmlsbD0iIzUzM0FGRCIvPjxwYXRoIGQ9Ik0xMjAgMzkyTDM5MiAzMzRWMTEyTDEyMCAxNz hWMzk yWiIgZmlsbD0iI2ZmZiIvPjwvc3ZnPg=='
)]
class AeStripe extends Gateway
{
    use Concerns\InvoicePayments, Concerns\WebhookContinuity, CardDuplicates, PaymentFees, Signature, Checkout, Amounts, SubscriptionAttempt, SubscriptionPayments, CheckoutIntent, ChargeRecovery, SetupConsent, SetupIntent, Webhooks, StripeMetadata, TransactionMethod;

    private const API_VERSION = '2025-07-30.basil';

    public function boot()
    {
        if (app()->runningInConsole()) {
            app()->booted(fn () => \Illuminate\Support\Facades\Schedule::call(fn () => $this->retryChargeAttempts())
                ->name('ae-stripe:charge-attempts')->everyFiveMinutes()->onOneServer()->withoutOverlapping(60));
            app()->booted(fn () => \Illuminate\Support\Facades\Schedule::call(fn () => $this->retryReceiptReturns())
                ->name('ae-stripe:receipt-returns')->everyFiveMinutes()->onOneServer()->withoutOverlapping(60));
            app()->booted(fn () => \Illuminate\Support\Facades\Schedule::call(fn () => $this->retryPaymentFees())
                ->name('ae-stripe:payment-fees')->hourly()->onOneServer()->withoutOverlapping(55));
            require_once __DIR__ . '/ResolveChargeCommand.php';
            require_once __DIR__ . '/DeduplicateCardsCommand.php';
            require_once __DIR__ . '/AdoptBundledCommand.php';
            require_once __DIR__ . '/ConvertFromStripeSavedCommand.php';
            \Illuminate\Console\Application::starting(function ($artisan) {
                $artisan->resolve(ResolveChargeCommand::class);
                $artisan->resolve(DeduplicateCardsCommand::class);
                $artisan->resolve(AdoptBundledCommand::class);
                $artisan->resolve(ConvertFromStripeSavedCommand::class);
            });
        }
        require __DIR__ . '/routes.php';
        // Register webhook route
        View::addNamespace('gateways.ae-stripe', __DIR__ . '/resources/views');
        FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_START, fn () => $this->gatewayWarning());
        Event::listen(\App\Events\Invoice\Updated::class, function ($event) {
            if ($event->invoice->status === 'pending') return;
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($event) {
                try { $this->cancelInvoicePayments($event->invoice); }
                catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('AeStripe invoice cancellation will retry', ['invoice_id' => $event->invoice->id]); }
            });
        });
        // Core boots disabled gateways too. Let the bundled listener own legacy subscriptions.
        if (!$this->bundledInactive(requireAbsent: true)) {
            return;
        }

        Event::listen(Updated::class, function (Updated $event) {
            if ($event->service->properties->where('key', 'has_stripe_subscription')->first()?->value !== '1' || !$event->service->subscription_id) {
                // If the service is not a stripe subscription, skip
                return;
            }
            if ($this->gatewayEnabled() && ($event->service->isDirty('price') || $event->service->isDirty('expires_at'))) {
                try {
                    $this->updateSubscription($event->service);
                } catch (Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('AeStripe subscription sync failed', ['service_id' => $event->service->id]);
                }
            }
            // Check if the service is canceled
            if ($event->service->isDirty('status') && $event->service->status === Service::STATUS_CANCELLED) {
                try {
                    $this->cancelSubscription($event->service);
                } catch (Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('AeStripe subscription sync failed', ['service_id' => $event->service->id]);
                }
            }
        });

        Event::listen(Created::class, function (Created $event) {
            $service = $event->cancellation->service;
            if ($service->properties->where('key', 'has_stripe_subscription')->first()?->value !== '1' || !$service->subscription_id) {
                // If the service is not a stripe subscription, skip
                return;
            }
            try {
                $this->cancelSubscription($service);
            } catch (Exception $e) {
                \Illuminate\Support\Facades\Log::warning('AeStripe subscription sync failed', ['service_id' => $service->id]);
            }
        });
    }

    private function bundledInactive(bool $requireAbsent = false): bool
    {
        // Soft-deleted rows are excluded by the native model scope. Disabled rows still boot listeners.
        $bundled = \App\Models\Gateway::where('extension', 'Stripe');
        if (!$requireAbsent) $bundled->where('enabled', true);
        return !$bundled->exists();
    }

    public function canUseGateway($total, $currency, $type, $items): bool
    {
        return $this->gatewayEnabled() && $this->bundledInactive();
    }

    public function gatewayWarning()
    {
        $panel = Filament::getCurrentPanel();
        $user = Auth::user();
        if (!$panel || $panel->getId() !== 'admin' || !$user || !$user->canAccessPanel($panel) || !$this->gatewayEnabled()) return '';
        $gateways = [];
        foreach (\App\Models\Gateway::where('extension', 'Stripe')->where('enabled', true)->get() as $bundled) {
            $gateways[] = ['id' => $bundled->id, 'hasReferences' => GatewayLifecycle::hasReferences($bundled),
                'canUpdate' => $user->can('update', $bundled)];
        }
        return $gateways ? view('gateways.ae-stripe::gateway-warning', compact('gateways')) : '';
    }

    public function disableCore(Request $request)
    {
        $request->validate(['gateway_id' => 'required|integer']);
        $bundled = \App\Models\Gateway::where('extension', 'Stripe')->where('id', $request->input('gateway_id'))->firstOrFail();
        abort_unless(Auth::user()?->can('update', $bundled), 403);
        abort_unless($this->gatewayEnabled() && !GatewayLifecycle::hasReferences($bundled), 409);
        GatewayLifecycle::disable($bundled);
        Notification::make()->title('Core Stripe was turned off.')->success()->send();
        return redirect()->route('filament.admin.resources.gateways.index');
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'stripe_secret_key',
                'label' => 'Stripe restricted API key',
                'placeholder' => 'Enter your restricted API key',
                'type' => 'text',
                'encrypted' => true,
                'description' => 'Find your API keys at https://dashboard.stripe.com/apikeys',
                'required' => true,
            ],
            [
                'name' => 'stripe_publishable_key',
                'label' => 'Stripe publishable key',
                'placeholder' => 'Enter your publishable key',
                'type' => 'text',
                'description' => 'Find your API keys at https://dashboard.stripe.com/apikeys',
                'required' => true,
            ],
            [
                'name' => 'stripe_create_customers',
                'label' => 'Send address and phone to Stripe',
                'type' => 'checkbox',
                'description' => 'Include address, phone and company details for Stripe Tax and addresses on Stripe receipts.',
                'required' => false,
            ],
            [
                'name' => 'stripe_webhook_secret',
                'label' => 'Stripe webhook signing secret',
                'type' => 'text',
                'encrypted' => true,
                'description' => 'Created automatically when you enable the gateway.',
                'required' => false,
            ],
            [
                'name' => 'stripe_revoke_previous_secrets',
                'label' => 'Revoke previous signing secrets',
                'type' => 'checkbox',
                'description' => 'If a secret leaked, save its replacement and select this to stop accepting old secrets immediately. Clears after saving.',
                'required' => false,
            ],
        ];
    }

    public function enabled(Extension $gateway)
    {
        $this->updated($gateway);
        $this->disableEmptyBundled();
    }

    private function disableEmptyBundled(): void
    {
        $disabled = false;
        foreach (\App\Models\Gateway::where('extension', 'Stripe')->where('enabled', true)->get() as $bundled) {
            if (!GatewayLifecycle::hasReferences($bundled)) {
                GatewayLifecycle::disable($bundled);
                $disabled = true;
            }
        }
        if ($disabled) Notification::make()->title('Core Stripe was turned off.')->success()->send();
    }

    public function updated(Extension $gateway)
    {
        $this->updateWebhookContinuity($gateway);

        if ($gateway->enabled && $gateway->wasChanged('enabled')) $this->disableEmptyBundled();
    }

    private function request($method, $url, $data = [], ?string $idempotencyKey = null)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->config('stripe_secret_key'),
            'Stripe-Version' => self::API_VERSION,
        ];
        if ($idempotencyKey !== null) $headers['Idempotency-Key'] = $idempotencyKey;
        return $this->readStripeObject(Http::withHeaders($headers)->asForm()->connectTimeout(2)->timeout(5)->$method('https://api.stripe.com/v1' . $url, $data)->throw()->object());
    }

    private function setupSubscription($setupIntent)
    {
        $invoice = Invoice::findOrFail($setupIntent->metadata->invoice_id);
        $user = $invoice->user;
        $stripeCustomerId = $user->properties->where('key', 'stripe_id')->first();
        // Create customer if not exists
        if (!$stripeCustomerId) {
            throw new \Exception('Stripe customer not found');
        } else {
            $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
        }

        if (($setupIntent->customer ?? null) !== $customer->id) {
            \Illuminate\Support\Facades\Log::warning('AeStripe subscription setup customer mismatch', ['invoice_id' => $invoice->id]);
            return;
        }
        $method = $this->request('get', '/payment_methods/' . $setupIntent->payment_method);
        if (!$this->methodCustomerMatches($user, $method)) return;

        // Make payment method default
        $paymentMethod = $this->request('post', '/customers/' . $customer->id, [
            'invoice_settings' => ['default_payment_method' => $setupIntent->payment_method],
        ]);

        // Create subscription
        foreach ($invoice->items as $item) {
            if ($item->reference_type !== Service::class) {
                continue;
            }
            $service = $item->reference;
            if ($service->status === Service::STATUS_CANCELLED) continue;
            if ($service->subscription_id) {
                $service->properties()->updateOrCreate(['key' => 'has_stripe_subscription'], ['value' => true]);
                continue;
            }
            $product = $service->product;

            // Check if the service->product already exists in Stripe
            $stripeProduct = $this->request('get', '/products/search', ['query' => 'metadata[\'product_id\']:\'' . $product->id . '\'']);

            if (empty($stripeProduct->data)) {
                // Create product
                $stripeProduct = $this->request('post', '/products', [
                    'name' => $product->name,
                    'metadata' => ['product_id' => $product->id],
                ]);
            } else {
                $stripeProduct = $stripeProduct->data[0];
            }

            $phases = [];
            // Check if current invoice item price is bigger then service price (then we have a setup fee)
            if ($item->price != $service->price) {
                $phases[] = [
                    'items' => [
                        [
                            'price_data' => [
                                'currency' => $invoice->currency_code,
                                'product' => $stripeProduct->id,
                                'unit_amount' => $this->toMinorUnits($item->price, $invoice->currency_code),
                                'recurring' => [
                                    'interval' => $service->plan->billing_unit,
                                    'interval_count' => $service->plan->billing_period,
                                ],
                            ],
                            'quantity' => 1,
                        ],
                    ],
                    'iterations' => 1,
                    'metadata' => [
                        'service_id' => $service->id, 'ae_stripe_gateway' => '1',
                    ],
                    'proration_behavior' => 'none',
                ];
            }
            $phases[] = [
                'items' => [
                    [
                        'price_data' => [
                            'currency' => $invoice->currency_code,
                            'product' => $stripeProduct->id,
                            'unit_amount' => $this->toMinorUnits($service->price * $service->quantity, $invoice->currency_code),
                            'recurring' => [
                                'interval' => $service->plan->billing_unit,
                                'interval_count' => $service->plan->billing_period,
                            ],
                        ],
                        'quantity' => 1,
                    ],
                ],
                'metadata' => [
                    'service_id' => $service->id, 'ae_stripe_gateway' => '1',
                ],
                'proration_behavior' => 'none',
            ];

            $subscriptionId = $this->subscriptionAttempt($user, $setupIntent, $service, [
                'customer' => $customer->id,
                'start_date' => 'now',
                'phases' => $phases,
                'metadata' => ['service_id' => $service->id, 'ae_stripe_gateway' => '1'],
            ]);

            // Update service with subscription id
            $service->update(['subscription_id' => $subscriptionId]);
            $service->properties()->updateOrCreate(['key' => 'has_stripe_subscription'], ['value' => true]);
        }
    }

    public function updateSubscription(Service $service)
    {
        // Grab the schedule from Stripe
        $scheduleId = $this->request('get', '/subscriptions/' . $service->subscription_id);

        if ($service->isDirty('price')) {
            if ($scheduleId->schedule) {

                $oldPhases = $this->request('get', '/subscription_schedules/' . $scheduleId->schedule)->phases;
                // Overwrite phase 2 item 0 with the new price
                $phases = [];
                // Only keep items and end, start date
                foreach ($oldPhases as $phase) {
                    $phases[] = [
                        'items' => $phase->items,
                        'end_date' => $phase->end_date,
                        'start_date' => $phase->start_date,
                    ];
                }
                // Check if the service->product already exists in Stripe
                $product = $service->product;
                $stripeProduct = $this->request('get', '/products/search', ['query' => 'metadata[\'product_id\']:\'' . $product->id . '\'']);

                if (empty($stripeProduct->data)) {
                    // Create product
                    $stripeProduct = $this->request('post', '/products', [
                        'name' => $product->name,
                        'metadata' => ['product_id' => $product->id],
                    ]);
                } else {
                    $stripeProduct = $stripeProduct->data[0];
                }
                // Latest phase is the current one
                $key = count($phases) - 1;
                $phases[$key]['items'][0]->price_data = [
                    'currency' => $service->currency->code,
                    'unit_amount' => $this->toMinorUnits($service->price * $service->quantity, $service->currency->code),
                    'product' => $stripeProduct->id,
                    'recurring' => [
                        'interval' => $service->plan->billing_unit,
                        'interval_count' => $service->plan->billing_period,
                    ],
                ];
                $phases[$key]['items'][0]->price = null;
                $phases[$key]['items'][0]->plan = null;

                // Update the schedule
                $this->request('post', '/subscription_schedules/' . $scheduleId->schedule, [
                    'phases' => $phases,
                    'proration_behavior' => 'none',
                ]);
            } else {
                // Get subscription
                $subscription = $this->request('get', '/subscriptions/' . $service->subscription_id);
                // Get first item
                $item = $subscription->items->data[0];
                // Update price
                $this->request('post', '/subscription_items/' . $item->id, [
                    'price_data' => [
                        'currency' => $service->currency->code,
                        'unit_amount' => $this->toMinorUnits($service->price * $service->quantity, $service->currency->code),
                        'product' => $item->price->product,
                        'recurring' => [
                            'interval' => $service->plan->billing_unit,
                            'interval_count' => $service->plan->billing_period,
                        ],
                    ],
                    'proration_behavior' => 'none',
                ]);
            }
        }

        if ($service->isDirty('expires_at')) {
            $subDate = Carbon::createFromTimestamp($scheduleId->current_period_end)->startOfDay();
            // Check if current date is before the end date of the subscription
            if ($subDate == $service->expires_at || $service->expires_at <= $subDate) {
                return;
            }

            if ($scheduleId->schedule) {
                // As phases are only used for the setup fee, we can remove the phases
                $this->request('post', '/subscription_schedules/' . $scheduleId->schedule . '/release', []);

                // Get subscription
                $subscription = $this->request('get', '/subscriptions/' . $service->subscription_id);
                // Get first item
                $item = $subscription->items->data[0];
                // Update price
                $this->request('post', '/subscription_items/' . $item->id, [
                    'price_data' => [
                        'currency' => $service->currency->code,
                        'unit_amount' => $this->toMinorUnits($service->price * $service->quantity, $service->currency->code),
                        'product' => $item->price->product,
                        'recurring' => [
                            'interval' => $service->plan->billing_unit,
                            'interval_count' => $service->plan->billing_period,
                        ],
                    ],
                    'proration_behavior' => 'none',
                ]);
            }
            // Update the subscription
            $this->request('post', '/subscriptions/' . $service->subscription_id, [
                'trial_end' => $service->expires_at->timestamp,
                'proration_behavior' => 'none',
            ]);
        }
    }

    public function cancelSubscription(Service $service)
    {
        if (!$service->subscription_id) {
            return;
        }
        $this->request('delete', '/subscriptions/' . $service->subscription_id);

        // Remove subscription id from service
        $service->update(['subscription_id' => null]);
        // Remove has_stripe_subscription property
        $service->properties()->where('key', 'has_stripe_subscription')->delete();

        return true;
    }

    public function supportsBillingAgreements(): bool
    {
        return true;
    }

    /**
     * Ensure a Stripe Customer exists for the given user. If a customer already
     * exists it will be returned; otherwise a new customer will be created.
     *
     * @param  bool  $includeDetails  Whether to include address, phone, and company details
     * @return object Stripe customer object
     */
    private function createOrGetStripeCustomer($user, $includeDetails = true)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($user, $includeDetails) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            return $this->lockedStripeCustomer($user, $includeDetails);
        });
    }

    private function lockedStripeCustomer($user, bool $includeDetails)
    {
        $stripeCustomerId = $user->properties()->where('key', 'stripe_id')->lockForUpdate()->first();

        if ($stripeCustomerId) {
            try {
                $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
                if (($customer->deleted ?? false) !== true) {
                    return $customer;
                }
            } catch (RequestException $e) {
                if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') {
                    throw $e;
                }
            }
        }

        // Build basic payload
        $payload = [
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => $user->id],
        ];

        // Add detailed information if requested
        if ($includeDetails) {
            $props = $user->properties()->pluck('value', 'key')->toArray();

            if (!empty($props['company_name'])) {
                $payload['description'] = $props['company_name'];
            }

            $address = array_filter([
                'line1' => $props['address'] ?? null,
                'line2' => $props['address2'] ?? null,
                'city' => $props['city'] ?? null,
                'state' => $props['state'] ?? null,
                'postal_code' => $props['zip'] ?? null,
                'country' => $props['country'] ?? null,
            ]);

            if ($address) {
                $payload['address'] = $address;
            }

            if (!empty($props['phone'])) {
                $payload['phone'] = $props['phone'];
            }
        }

        $customer = $this->request('post', '/customers', $payload);

        $user->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => $customer->id]);

        return $customer;
    }

    public function createBillingAgreement($user)
    {
        abort_unless($this->gatewayEnabled(), 409);
        abort_unless(Auth::id() == $user->id, 403);
        // Create or fetch a Stripe customer for this user, use detailed info based on setting
        $includeDetails = (bool) $this->config('stripe_create_customers');
        $customer = $this->createOrGetStripeCustomer($user, $includeDetails);

        $setupIntent = $this->accountSetupIntent($user, [
            'metadata' => [
                'user_id' => $user->id,
                'is_billing_agreement' => '1',
                'ae_stripe_gateway' => '1',
            ],
            'usage' => 'off_session',
            'customer' => $customer->id,
        ]);

        // Return the client secret to the frontend to complete the setup
        return view('gateways.ae-stripe::billing-agreement', ['intent' => $setupIntent, 'type' => 'setup', 'stripePublishableKey' => $this->config('stripe_publishable_key')]);
    }

    public function cancelBillingAgreement(BillingAgreement $billingAgreement): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($billingAgreement) {
            $user = User::where('id', $billingAgreement->user_id)->lockForUpdate()->firstOrFail();
            $current = BillingAgreement::withTrashed()->where('external_reference', $billingAgreement->external_reference)->lockForUpdate()->first();
            if (!$current) return false;
            $gateway = \App\Models\Gateway::where('extension', 'AeStripe')->firstOrFail();
            if ($current->id != $billingAgreement->id || $current->user_id != $user->id || $current->gateway_id != $gateway->id) return false;
            if ($current->trashed()) return true;
            try {
                $method = $this->request('get', '/payment_methods/' . $current->external_reference);
            } catch (Exception $e) {
                \Illuminate\Support\Facades\Log::warning('ae_stripe_cancel_method_read_failed', ['error' => get_class($e)]);
                return true;
            }
            if (($method->customer ?? null) === null) return true;
            if (!$this->methodCustomerMatches($user, $method, current: true)) return false;
            try {
                $this->request('post', '/payment_methods/' . $current->external_reference . '/detach');
            } catch (Exception $e) {
                // Native cancellation still removes the local agreement after a Stripe failure.
            }
            return true;
        });
    }

    public function setupAgreement(Request $request)
    {
        abort_unless($this->gatewayEnabled(), 409);
        $request->validate([
            'setup_intent' => 'required|string|regex:/^seti_[a-zA-Z0-9]{24,}$/|max:255',
        ]);

        $setupIntent = $this->request('get', '/setup_intents/' . $request->input('setup_intent'));

        if ($setupIntent->status !== 'succeeded') {
            return redirect()->route('account.payment-methods')->with('notification', [
                'type' => 'danger',
                'message' => 'Could not add your payment method. Try again.',
            ]);
        }
        // Validate if user id matches
        if (Auth::id() != $setupIntent->metadata->user_id) {
            abort(403, 'Unauthorized');
        }

        try {
            $saved = $this->setupBillingAgreement($setupIntent);
            abort_unless($saved, 409);
        } catch (DisplayException $e) {
            return redirect()->route('account.payment-methods')->with('notification', [
                'type' => 'danger', 'message' => $e->getMessage(),
            ]);
        }

        return redirect()->route('account.payment-methods')->with('notification', [
            'type' => 'success',
            'message' => $saved === 'duplicate' ? 'This card is already saved.' : 'Payment method added successfully.',
        ]);
    }

    private function setupBillingAgreement($setupIntent)
    {
        // Get setup intent with expanded data
        $setupIntent = $this->request('get', '/setup_intents/' . $setupIntent->id, [
            'expand' => ['latest_attempt'],
        ]);

        if (($setupIntent->metadata->is_billing_agreement ?? null) !== '1'
            || ($setupIntent->metadata->ae_stripe_setup_consent ?? null) !== 'off_session_v1'
            || empty($setupIntent->metadata->ae_stripe_setup_consent_at)
            || ($setupIntent->metadata->ae_stripe_setup_consent_user ?? null) != ($setupIntent->metadata->user_id ?? null)
            || $setupIntent->status !== 'succeeded') return false;
        $user = User::findOrFail($setupIntent->metadata->user_id);
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        if (!$customer || ($setupIntent->customer ?? null) !== $customer) return false;

        // Get the actual payment method that will be used for future charges
        $actualPaymentMethod = $this->getActualPaymentMethod($setupIntent);

        return $this->persistAgreement($user, $actualPaymentMethod, deduplicate: true);
    }

    private function getActualPaymentMethod($setupIntent)
    {
        $latestAttempt = $setupIntent->latest_attempt;

        // Check if the payment method was converted to another type
        if ($latestAttempt && isset($latestAttempt->payment_method_details)) {
            $details = $latestAttempt->payment_method_details;

            if (in_array($details->type, ['ideal', 'bancontact', 'sofort'])) {
                // These payment methods can be converted to SEPA Direct Debit
                return $this->request('get', '/payment_methods/' . $details->{$details->type}->generated_sepa_debit);
            }
        }

        // If no conversion, use the original payment method
        return $this->request('get', '/payment_methods/' . $setupIntent->payment_method);
    }

    private function getPaymentDetails($paymentMethod)
    {
        $linkEmail = $paymentMethod->link->email ?? $paymentMethod->billing_details->email ?? null;
        if (is_string($linkEmail) && filter_var($linkEmail, FILTER_VALIDATE_EMAIL)) {
            [$local, $domain] = explode('@', $linkEmail, 2);
            $linkEmail = substr($local, 0, 1) . '***@' . $domain;
        } else {
            $linkEmail = null;
        }
        $name = match ($paymentMethod->type) {
            'link' => $linkEmail ? 'Link · ' . $linkEmail : 'Link',
            'card' => match ($paymentMethod->card->brand) {
                'amex' => 'American Express',
                'diners' => 'Diners Club',
                'discover' => 'Discover',
                'jcb' => 'JCB',
                'mastercard' => 'Mastercard',
                'unionpay' => 'UnionPay',
                'visa' => 'Visa',
                default => ucfirst($paymentMethod->card->brand),
            } . ' **** ' . $paymentMethod->card->last4,

            'sepa_debit' => 'SEPA Direct Debit **** ' . $paymentMethod->sepa_debit->last4,
            'ideal' => 'iDEAL **** ' . $paymentMethod->ideal->bank_code,
            'bancontact' => 'Bancontact **** ' . $paymentMethod->bancontact->bank_code,
            'sofort' => 'SOFORT **** ' . strtoupper($paymentMethod->sofort->country),
            'us_bank_account' => 'US Bank Account **** ' . $paymentMethod->us_bank_account->last4,
            'bacs_debit' => 'Bacs Direct Debit **** ' . $paymentMethod->bacs_debit->last4,
            'au_becs_debit' => 'BECS Direct Debit **** ' . $paymentMethod->au_becs_debit->last4,

            default => ucfirst(str_replace('_', ' ', $paymentMethod->type)),
        };
        $type = match ($paymentMethod->type) {
            'card' => $paymentMethod->card->brand,
            // For the others, just return the type as is
            default => $paymentMethod->type,
        };
        $expiry = null;
        if ($paymentMethod->type === 'card' && isset($paymentMethod->card->exp_year, $paymentMethod->card->exp_month)) {
            $expiry = Carbon::createFromDate(
                $paymentMethod->card->exp_year,
                $paymentMethod->card->exp_month,
                1
            )->endOfMonth()->format('Y-m-d');
        }

        return [
            'name' => $name,
            'type' => $type,
            'expiry' => $expiry,
        ];

    }

    public function charge(Invoice $invoice, $amount, BillingAgreement $billingAgreement)
    {
        $this->paymentRetryReserved = false;
        $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
        if (!$customer) {
            throw new DisplayException('Could not find your saved payment details. Add a new payment method.');
        }
        try {
            $data = $this->checkoutPaymentData($invoice, $this->toMinorUnits($amount, $invoice->currency_code), $customer)
                + ['agreement_id' => $billingAgreement->id];
            if (!$this->gatewayEnabled()) {
                $this->reservePaymentAttempt($invoice, $data);
                $this->paymentPending('Payments are paused. We will check this payment when payments resume.');
            }
            $intent = $this->checkoutIntent($invoice, $data);
            if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)) {
                $intent = $this->confirmAttempt($invoice, $intent->id, [
                    'payment_method' => $billingAgreement->external_reference, 'off_session' => 'true',
                ], $billingAgreement);
            }
            if ($intent->status === 'succeeded') {
                try { $this->recordIntentPayment($intent, 'succeeded'); }
                catch (\Throwable $e) {
                    report($e);
                    if (!InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists()) {
                        $this->paymentPending('Stripe received this payment. We will finish recording it automatically.');
                    }
                }
                if (InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists()) return true;
                $this->paymentReconcileMessage();
            }
            if (in_array($intent->status, ['processing', 'requires_capture'], true)) $this->paymentPending();
            if ($intent->status === 'requires_action') throw new DisplayException('This payment needs authentication. Use the one-time payment option to finish it.');
            throw new DisplayException('This card was declined. Choose another payment method.');
        } catch (\PDOException | \Illuminate\Database\QueryException $e) {
            // Before reservation there is no durable retry: let core surface that failure.
            // Afterwards even a lost DB connection may follow a landed Stripe confirm.
            if (!$this->paymentRetryReserved) throw $e;
            report($e);
            if (app()->runningInConsole()) return true;
            $this->paymentPending('We are checking this payment automatically. Refresh the invoice for its status.');
        } catch (PaymentPendingException $e) {
            // Core cron ignores the bool and emails failure for every thrown exception.
            if (app()->runningInConsole()) return true;
            throw $e;
        }
    }

}
