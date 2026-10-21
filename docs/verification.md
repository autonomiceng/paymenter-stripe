# Verification

Offline, with PHP 8.3+ and Python 3:

```sh
php -n tests/run.php
find extension tests -name '*.php' ! -name '*.blade.php' -exec php -n -l {} \;
python3 tools/build_release.py --output dist/StripeSaved-0.1.0.zip
(cd dist && sha256sum -c StripeSaved-0.1.0.zip.sha256)
```

Account setup displays an unchecked opt-in: “I agree to save this payment method
and allow charges to it for my future invoices, including renewals, when I am not
present.” It also states: “Saving does not turn on Auto Pay; you can choose it for
a service later.” Before confirmation, the
authenticated CSRF-protected setup-consent endpoint checks the SetupIntent's user,
customer, purpose and unfinished status, then records `off_session_v1`, authenticated
user ID and server acceptance time in Stripe metadata. Webhook and redirect both
require that record and matching customer before persisting an agreement. Existing
agreements are unchanged; unconsented in-flight setups must restart with the opt-in.
Both payment forms stay disabled until Stripe.js, Payment Element and submit handler are
ready; initialization and confirmation errors remain in the alert for retry.

Tests execute the gateway with PHP-only model, lock and HTTP fakes; unexpected
HTTP requests fail. No Stripe or application server is contacted. They verify
customer binding, consent, intent updates, agreement retry keys/locking, labels,
signatures, disabled handling and monotonic successful payments. Amounts use
integer minor units with half-up rounding and Stripe zero-decimal currencies
(including the UGX/ISK whole-unit, two-decimal API compatibility representation);
subscription price updates preserve service quantity. Setup subscriptions skip services
already linked to Stripe, repair an interrupted listener marker write, and persist immutable schedule payloads and SetupIntent/service
retry keys before remote creation. Completed schedule IDs survive local write failures;
unresolved attempts older than 23 hours require reconciliation, not another schedule.
Subscription invoice creation binds a single pending related invoice in Stripe metadata
before finalizing/paying, recovering the current immutable binding on retries; ambiguous creation and unbound success events require manual
reconciliation. Success requires that exact pending related invoice and matching
currency; split payments require manual reconciliation. Both delivery paths serialize settlement on the customer and use locking current
reads to skip a successful transaction globally, so one PaymentIntent cannot settle two local invoices. A resumed
3DS attempt shows and preserves its existing consent without changing payment preferences.

Checkout renders persist a retry key and PaymentIntent ID in native user properties
before and after creation. Reopening retrieves the same unfinished intent. A changed
invoice payload, finished/cancelled intent, or unresolved creation older than 23 hours
returns 409; reconcile the stored `stripe_saved_checkout_<invoice-id>` property
against Stripe before clearing it for a new attempt. Never clear an unresolved or
settled payment to retry. Reconcile `stripe_saved_subscription_<service-id>`
in the same way before removing an unresolved schedule attempt. This intentionally requires operator reconciliation rather
than creating a second charge after Stripe's idempotency retention window. Fakes do not
prove real database isolation, native uploader/route behavior or Stripe.js/API
compatibility. Validate these in a separate Paymenter/Stripe test-mode sandbox:

- Install beside bundled Stripe; ensure unique routes/views/config and no
  duplicate subscription listeners even when bundled Stripe is disabled.
- Check CustomerSession feature names on pinned Basil, restricted-key access,
  `customerSessionClientSecret`, saved-card selection and Link email display.
- Verify setup cannot save without the opt-in, recorded terms/user/time survive
  redirect and webhook retries, and setup errors permit retry.
- Verify own-customer isolation, legacy unspecified-redisplay exclusions and
  account setup redisplay. Test card, wallet and Link with saving checked/clear.
- Confirm clearing future usage with `''` and `elements.fetchUpdates()` refresh
  the intent before confirmation, including decline, 3DS and redirect retries.
- Run concurrent success deliveries/setup redirects against the real database;
  assert one agreement/transaction, preserved success after late failed/processing
  events, no automatic service assignment, and unchanged Auto Pay.
- Reject bad/stale signatures; disable the fork and confirm 200/no effects.
  Rehearse migration and legacy subscription billing/cancellation end to end.

Customer setup failures stop checkout with a plain retry message. A rejected Customer
Session request logs only the invoice ID and HTTP status; checkout still accepts a
new payment method without saved-method redisplay. Permanent saved-method customer
mismatches are logged and acknowledged after settlement. Transient request and local
persistence failures remain retryable; retries preserve one payment and one agreement.
Verify Customer Session failure and recovery in the sandbox as well as normal redisplay.

Checkout's opt-in covers card and Link only. Consent sets (or clears with `''`)
`payment_method_options.card.setup_future_usage` and the matching Link option,
never top-level future usage. Saving requires consent metadata and `off_session`
for the actual method type. Bank debits are not saved by this checkout opt-in.
Verify both method-specific options against pinned Basil before release.
