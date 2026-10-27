# Verification

Offline, with PHP 8.3+ and Python 3:

```sh
php -n tests/run.php
find extension tests -name '*.php' ! -name '*.blade.php' -exec php -n -l {} \;
python3 tools/build_release.py --output dist/AeStripe.zip
(cd dist && sha256sum -c AeStripe.zip.sha256)
```

Upgrade through the native admin upload over an installed copy; root PHP files must declare only classes for Paymenter 1.5.9 `getExtensionType()`.

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
Browser-check both pay and setup views inside the theme's teleported modal (default theme `x-teleport="body"`).
Offline browser coverage in `tests/setup-modal.mjs` checks repeated setup opens,
declines, consent failures and closing during Stripe.js loading. Each form releases
its Element and theme listeners when Alpine removes it. An unfinished account
SetupIntent is reused across opens; only a confirmed `succeeded` or `canceled`
intent permits a new attempt. A lost creation response retries the persisted key.
An unresolved creation older than 23 hours requires reconciliation of the native
`ae_stripe_setup` user property against Stripe before trying again. Do not clear an
unfinished attempt to retry. SetupIntents created before this update have no stored
retry key and cannot be recovered by the new property.
A stored intent that cannot be retrieved, including a 404 after changing API keys,
also needs reconciliation in its original Stripe account. Lookup failures never
start a replacement intent.

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
reconciliation. Success requires that exact related invoice, the same owner and matching
currency. A bound paid invoice retains a late successful payment; other nonpending
invoices retain a staff review record. Split payments require manual reconciliation.
Both delivery paths serialize settlement on the customer and use locking current
reads to skip a successful transaction globally, so one PaymentIntent cannot settle two local invoices. A resumed
3DS attempt shows and preserves its existing consent without changing payment preferences.

Agreement saves use a locking current read by the globally unique payment method
reference, including soft-deleted rows. Retries reuse only the same user's agreement
on the same gateway, including after an insert race. Removed methods stay removed
to preserve the deletion and service payment preferences. The setup return shows a
clear refusal notification; webhooks log and acknowledge permanent ownership or
deletion conflicts. Request and database failures remain retryable. Offline tests
cover stale snapshots and insert conflicts with fakes; real MariaDB concurrency
still needs the sandbox checks below.

Checkout renders persist a retry key and PaymentIntent ID in native user properties
before and after creation. Reopening retrieves the same unfinished intent. A changed
invoice payload, finished/cancelled intent, or unresolved creation older than 23 hours
returns 409; reconcile the stored `ae_stripe_checkout_<invoice-id>` property
against Stripe before clearing it for a new attempt. Never clear an unresolved or
settled payment to retry. Reconcile `ae_stripe_subscription_<service-id>`
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
- Reject bad/stale signatures; disable the fork and confirm 503/no effects.
  Rehearse migration and legacy subscription billing/cancellation end to end.

Customer setup failures stop checkout with a plain retry message. A rejected Customer
Session request logs only the invoice ID and HTTP status; checkout still accepts a
new payment method without saved-method redisplay. Permanent saved-method customer
mismatches are logged and acknowledged after settlement. Transient request and local
persistence failures remain retryable; retries preserve one payment and one agreement.
Verify Customer Session failure and recovery in the sandbox as well as normal redisplay.

Checkout's opt-in covers card and Link only. Consent sets (or clears with `''`)
`payment_method_options.card.setup_future_usage` and the matching Link option,
and clears top-level future usage left by earlier versions. Saving requires
consent metadata and `off_session` for the actual method type. Bank debits are not saved by this checkout opt-in.
Verify both method-specific options against pinned Basil before release.

A successful checkout or bound subscription charge on an already paid invoice is recorded as another
native successful transaction. The invoice stays paid. Paymenter 1.5.9 keeps the
extra amount in the invoice transactions; it does not automatically create credit.
Staff should review the transaction ID in Stripe and either refund the extra
charge there or add the agreed credit under the user's **Credits** page in
Paymenter. No refund or credit is created automatically.

A successful charge refused for a customer or currency mismatch, or a subscription
charge on a cancelled invoice, is not applied to the invoice. A record in Paymenter's native invoice **Audit Log** retains the
PaymentIntent ID, invoice ID, received amount in Stripe minor units, currency and
reason. Open the invoice's **Audit Log** action, or **System > Audits**, to read
the `ae_stripe_payment_review` entry and reconcile the charge in Stripe before
refunding or crediting the correct customer. These records use the native audit
table, with no retention limit in Paymenter 1.5.9's default audit configuration.
Retries reuse the same record. Failure to store it leaves the webhook retryable.
These records contain no customer details or client secrets. Verify the native
page and concurrent delivery behavior in the sandbox.

Successful transactions and review records use the PaymentIntent's `amount_received`,
in its original currency. Subscription settlement retrieves the PaymentIntent before
recording it; lookup failures leave the webhook retryable. Processing and failed
transactions retain the intended `amount`. The extension does not request manual
capture. Verify differing intended and received amounts in the sandbox as well.

An existing Stripe customer is replaced only when Stripe returns `deleted: true`
or HTTP 404 with `resource_missing`. Other lookup failures stop the operation and
preserve `stripe_id`. Before confirmation, consent updates also clear legacy
top-level `setup_future_usage` with an empty form value. During authentication,
consent changes still return 409 and resuming performs no update. Include an
unfinished intent from the earlier release in the sandbox consent checks.

Fee recovery requires read access to Charges, PaymentIntents, Invoices, Invoice
Payments and Balance Transactions. Check the full [Stripe API permissions](settings.md#stripe-api-permissions)
with the configured restricted key in the sandbox.

Fee recovery uses the `ae_stripe_payment_fees` extension table, created on install
or upgrade, with a fallback on the first fee operation. Keep Paymenter's scheduler running every minute. The hourly
recovery task checks pending fees in batches of 100, ordered by attempt count.
A missing balance transaction, missing fee data or invalid conversion rate stays pending.
Pending funds already have a final fee. No rate is estimated.
Stripe lookups finish before user and invoice locks serialize webhook and task writes. Completed records remain
as replay guards. After 24 attempts or 72 hours, the record moves to `review`.
Staff receive one native inbox notice per recipient. The invoice Audit Log and
System > Audits retain a `ae_stripe_fee_review` entry with the identifiers and error.
Deleted payments also move to review without blocking the next fee.
Resolve these records manually against Stripe; do not delete completed records.
Existing webhook endpoints may keep `charge.updated`; new endpoints also include
`charge.succeeded`. A charge belonging to this gateway and awaiting native settlement returns 409 for retry.
Foreign charges, refused payments and subscriptions held for review are acknowledged.
Only paid, successful charges create fee records. Fees skipped before this upgrade
have no recovery record and require manual reconciliation.

Unresolved subscription successes retain one `ae_stripe_subscription_review`
entry per Stripe invoice in System > Audits. This covers
missing services or bindings and incomplete or split payments. Review the Stripe
invoice before applying money. Refund and dispute events remain unsupported.
Offline concurrency tests interleave deliveries before lock acquisition; validate
real database exclusion, migration upgrades, scheduler discovery and staff inbox
delivery in the Paymenter sandbox before release.

Confirmed and processing payments retain a display label in the extension-owned
`ae_stripe_transaction_methods` table, keyed by native invoice transaction ID.
The install and upgrade hooks run its migration. Expanded latest-charge details
need no lookup; an unexpanded charge needs one retrieve. Missing details or a
failed lookup leave the label blank without changing settlement. A failed attempt clears its
label so a later retry can record its own method. The first successful label
survives replayed success and late failure events. Link labels mask the email local
part. No card numbers, fingerprints or complete provider objects are stored.
Rendering never contacts Stripe. Earlier transactions are not backfilled.

With a pristine Paymenter release's dependencies available, also run
`PAYMENTER_TEST_APP=/path/to/pristine-release php tests/native-transaction-method.php`
to verify the migration, upgrade, unique key, foreign key and rollback on SQLite.

Optional transaction-method charge retrieval runs before settlement locks. Subscription PaymentIntent reads expand `latest_charge` before acquiring those locks. The locked handler rechecks customer, currency, invoice state and settled-payment replay before storing the label.
