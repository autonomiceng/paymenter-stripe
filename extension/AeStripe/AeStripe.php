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
use Paymenter\Extensions\Gateways\AeStripe\Concerns\PaymentFees;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\CheckoutIntent;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\SetupConsent;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\Signature;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\SubscriptionAttempt;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\SubscriptionPayments;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\Webhooks;
use Paymenter\Extensions\Gateways\AeStripe\Concerns\StripeMetadata;
use Str;

require_once __DIR__ . '/GatewayLifecycle.php';
require_once __DIR__ . '/Concerns/StripeMetadata.php';
require_once __DIR__ . '/Concerns/Signature.php';
require_once __DIR__ . '/Concerns/Checkout.php';
require_once __DIR__ . '/Concerns/Amounts.php';
require_once __DIR__ . '/Concerns/SubscriptionAttempt.php';
require_once __DIR__ . '/Concerns/SubscriptionPayments.php';
require_once __DIR__ . '/Concerns/CheckoutIntent.php';
require_once __DIR__ . '/Concerns/SetupConsent.php';
require_once __DIR__ . '/Concerns/Webhooks.php';
require_once __DIR__ . '/Concerns/PaymentFees.php';

#[ExtensionMeta(
    name: 'AE Stripe',
    description: 'Stripe Payment Element with saved methods and optional card saving.',
    version: '0.1.1',
    author: 'Autonomic Engineering',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48cmVjdCB3aWR0aD0iNTEyIiBoZWlnaHQ9IjUxMiIgZmlsbD0iIzUzM0FGRCIvPjxwYXRoIGQ9Ik0xMjAgMzkyTDM5MiAzMzRWMTEyTDEyMCAxNz hWMzk yWiIgZmlsbD0iI2ZmZiIvPjwvc3ZnPg=='
)]
class AeStripe extends Gateway
{
    use PaymentFees, Signature, Checkout, Amounts, SubscriptionAttempt, SubscriptionPayments, CheckoutIntent, SetupConsent, Webhooks, StripeMetadata;

    private const API_VERSION = '2025-07-30.basil';

    public function boot()
    {
        if (app()->runningInConsole()) {
            app()->booted(fn () => \Illuminate\Support\Facades\Schedule::call(fn () => $this->retryPaymentFees())
                ->name('ae-stripe:payment-fees')->hourly()->onOneServer()->withoutOverlapping(55));
            require_once __DIR__ . '/AdoptBundledCommand.php';
            require_once __DIR__ . '/ConvertFromStripeSavedCommand.php';
            \Illuminate\Console\Application::starting(function ($artisan) {
                $artisan->resolve(AdoptBundledCommand::class);
                $artisan->resolve(ConvertFromStripeSavedCommand::class);
            });
        }
        require __DIR__ . '/routes.php';
        // Register webhook route
        View::addNamespace('gateways.ae-stripe', __DIR__ . '/resources/views');
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START, fn () => $this->gatewayWarning());
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
                'label' => 'Stripe Restricted key',
                'placeholder' => 'Enter your Stripe Restricted API key',
                'type' => 'text',
                'encrypted' => true,
                'description' => 'Find your API keys at https://dashboard.stripe.com/apikeys',
                'required' => true,
            ],
            [
                'name' => 'stripe_publishable_key',
                'label' => 'Stripe Publishable Key',
                'placeholder' => 'Enter your Stripe Publishable API key',
                'type' => 'text',
                'description' => 'Find your API keys at https://dashboard.stripe.com/apikeys',
                'required' => true,
            ],
            [
                'name' => 'stripe_create_customers',
                'label' => 'Create detailed Stripe customers',
                'type' => 'checkbox',
                'description' => 'Controls the level of detail for Stripe customers. When enabled, customers include full address, phone, and company details. When disabled, customers include only basic information (name, email, user ID). Applies to both one-time payments and billing agreements.',
                'required' => false,
            ],
            [
                'name' => 'stripe_webhook_secret',
                'label' => 'Stripe webhook secret (auto generated)',
                'type' => 'text',
                'encrypted' => true,
                'description' => 'Stripe webhook secret',
                'required' => false,
            ],
        ];
    }

    public function enabled(Extension $gateway)
    {
        $this->disableEmptyBundled();
        $this->updated($gateway);
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
        if ($gateway->enabled && $gateway->wasChanged('enabled')) $this->disableEmptyBundled();
        if (!empty($gateway->settings()->where('key', 'stripe_webhook_secret')->first()->value)) {
            return;
        }

        // If the extension isn't enabled, try to boot it
        if (!Route::has('extensions.gateways.ae-stripe.webhook')) {
            require __DIR__ . '/routes.php';
        }

        // Check if webhook already exists
        $webhooks = $this->request('get', '/webhook_endpoints');
        foreach ($webhooks->data as $webhook) {
            if ($webhook->url === route('extensions.gateways.ae-stripe.webhook')) {
                // Delete webhook
                $this->request('delete', '/webhook_endpoints/' . $webhook->id);
                break;
            }
        }

        // Create webhook on stripe
        $webhook = $this->request('post', '/webhook_endpoints', [
            'url' => route('extensions.gateways.ae-stripe.webhook'),
            'description' => 'Paymenter Stripe Webhook',
            'enabled_events' => [
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'payment_intent.processing',
                'setup_intent.succeeded',
                'subscription_schedule.canceled',
                'invoice.created',
                'invoice.payment_succeeded',
                'charge.updated',
                'charge.succeeded',
            ],
            'api_version' => self::API_VERSION,
        ]);

        $gateway->settings()->updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $webhook->secret]);

        Notification::make()
            ->success()
            ->title('Webhook created')
            ->body('We\'ve created a webhook for you on Stripe (refresh the page to see the secret)')
            ->send();
    }

    private function request($method, $url, $data = [], ?string $idempotencyKey = null)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->config('stripe_secret_key'),
            'Stripe-Version' => self::API_VERSION,
        ];
        if ($idempotencyKey !== null) $headers['Idempotency-Key'] = $idempotencyKey;
        return $this->readStripeObject(Http::withHeaders($headers)->asForm()->$method('https://api.stripe.com/v1' . $url, $data)->throw()->object());
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
        $stripeCustomerId = $user->properties()->where('key', 'stripe_id')->first();

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

        $setupIntent = $this->request('post', '/setup_intents', [
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
        try {
            $request = $this->request('post', '/payment_methods/' . $billingAgreement->external_reference . '/detach');
        } catch (Exception $e) {
            // If the payment method is already removed, we still delete the billing agreement
        }

        return true;
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
                'message' => 'Could not add payment method, setup not completed successfully.',
            ]);
        }
        // Validate if user id matches
        if (Auth::id() != $setupIntent->metadata->user_id) {
            abort(403, 'Unauthorized');
        }

        try {
            abort_unless($this->setupBillingAgreement($setupIntent), 409);
        } catch (DisplayException $e) {
            return redirect()->route('account.payment-methods')->with('notification', [
                'type' => 'danger', 'message' => $e->getMessage(),
            ]);
        }

        return redirect()->route('account.payment-methods')->with('notification', [
            'type' => 'success',
            'message' => 'Payment method added successfully.',
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

        return $this->persistAgreement($user, $actualPaymentMethod);
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
        if ($paymentMethod->type === 'card') {
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
        $user = $invoice->user;
        $stripeCustomerId = $user->properties->where('key', 'stripe_id')->first();
        // Create customer if not exists
        if (!$stripeCustomerId) {
            throw new DisplayException('Stripe customer not found');
        } else {
            $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
        }

        // Create payment intent
        try {
            $intent = $this->request('post', '/payment_intents', [
                'amount' => $this->toMinorUnits($amount, $invoice->currency_code),
                'currency' => $invoice->currency_code,
                'customer' => $customer->id,
                'payment_method' => $billingAgreement->external_reference,
                'off_session' => 'true',
                'confirm' => 'true',
                'metadata' => ['invoice_id' => $invoice->id, 'billing_agreement_id' => $billingAgreement->id, 'ae_stripe_gateway' => '1'],
            ]);
        } catch (Exception $e) {
            if ($e instanceof RequestException && $e->response->status() === 400) {
                $error = $e->response->object()->error;
                // If error is invalid_request_error and message contains "The provided currency" we can show the message
                if ($error->type === 'invalid_request_error' && Str::contains($error->message, 'The currency provided')) {
                    throw new DisplayException('Cannot charge the billing agreement because the card does not support the invoice currency (' . $invoice->currency_code . '). Please use another payment method.');
                }
            }
            throw new DisplayException('Could not process payment');
        }

        return true;
    }

}
