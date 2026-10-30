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
declines, consent failures and closing during Stripe.js loading.
Fresh Stripe.js loads have a 30-second bound; timed-out owned tags are removed for retry.
Reused tags keep a five-second wait and preserve the owner's tag.
After a timeout, error event or load without the library, retry skips the foreign tag and loads its own.
Each form releases
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

Saved-card Pay, checkout and cron share `ae_stripe_checkout_<invoice-id>` in native
user properties. New records have `version: 2`. Creation reserves a key and immutable
parameters before HTTP and never confirms or supplies a card. The intent uses manual
confirmation: its client secret cannot start a payment from an old tab. Opening checkout
only prepares the form. Elements uses deferred `mode: payment` with the intent's integer amount and lowercase currency,
plus manual payment-method creation. BLIK and ACSS are excluded because this integration path does not support them; this form also excludes customer balance.
Ilya checked creation in Stripe test mode on 2026-10-30 at 12:00 PM PT (2025-07-30.basil): manual confirmation alone returned 200; adding automatic_payment_methods returned 400.
The returned card, Klarna, Link and Cash App defaults are all allowed by the form's exclusions; their token confirmation still needs the live checks below.
Submission sends a ConfirmationToken to the authenticated,
CSRF-protected confirm endpoint. Under user then invoice locks, the server retrieves
the intent, rechecks the balance, customer and active saved agreement, updates the amount,
and confirms. Locks remain held through confirmation to keep the balance and card checks valid.
Every Stripe request has a five-second timeout and a two-second connection timeout;
the four-call confirmation section is bounded by those individual request limits.
A Stripe timeout stays pending. After a saved instruction commits, database errors also stay pending for automatic recovery, without a failed-payment email. A first-lock failure before reservation surfaces as a failure because nothing was saved to retry. Paused saved charges are reserved without HTTP and resume when the gateway is enabled. A changed checkout
amount requires a refresh because the customer has not agreed to the new total.
3DS uses `handleNextAction`; its second confirmation and bank redirects return through
the same server checks, preserving the amount agreed before authentication. Card/Link saving consent remains explicit and method-specific.

A lost confirm response is recovered by intent ID, beyond key expiry. An unconfirmed
creation can be abandoned after 23 hours because it cannot have taken money. No frozen
confirmation is replayed. Bank verification that finishes later is polled and reconfirmed without replaying its consumed token.
Definite results clear the saved agreement instruction. New confirmations retain the preceding charge ID so an earlier failed row or error cannot cancel a later unknown confirmation. Older confirmations without that baseline honor a known failure and do not retry that debit. Recovery never repeats a failed
card or bank debit; only a new Pay can send another debit. Authentication resumes keep
the agreement in the separate confirmation record. Recovery records definite declines
for native failed-payment notices and adds one plain customer notice with the invoice link.
Processing bank debits show pending and block another payment,
including pre-upgrade Processing transactions with no attempt property. Recorded successes
with an unpaid remainder finish their attempt so the rest can be paid. Fully settled
attempt properties are removed. Sent review-alert markers remain for durable deduplication.
Core cron treats every exception as a failed-payment email; the extension returns its required
boolean for pending/uncertain console calls and shows a pending message for customer calls.
This also means core's successful-charge counter includes pending console outcomes.

The five-minute recovery task reads unresolved payments and retries interrupted operations
using the current balance and active agreement. It stops its batch on a Stripe outage and
rotates checked records so one outage does not hold the whole queue. Keep Paymenter's
scheduler running. Notification failures are retried separately after settlement commits.
Only definite write refusals clear a no-ID creation; lookup errors never prove a charge failed.
Currency and card refusals explain the reason and permit another method or a repaired setup.
Cancellation requests have no persistent failure-cache key; replacement still needs a
Stripe-confirmed cancellation with no received funds.

No schema migration is needed. Existing known IDs are retrieved. Existing unfinished
automatic intents are canceled before replacement, invalidating old tabs. Processing
intents remain protected until Stripe settles or fails them. Old no-ID records with
nonconfirming parameters can replay creation safely; digest-only records do so only when
an exact AE Stripe or converted StripeSaved checkout payload can be reconstructed.
R3 version-2 creations with no ID and the invalid automatic/manual parameter pair are replaced automatically: their creation could not charge. The pending saved instruction survives replacement. Known IDs are unchanged.
Old `confirm=true` payloads are never replayed. A matching attempt metadata key or webhook
request idempotency key can supply their identity; an invoice metadata search alone cannot.
Older unresolved records and saved instructions stranded without retry:true are included in automatic recovery without a manual property edit. A failed result still clears the old saved instruction; it never starts another debit.

An unresolved legacy identity, missing known intent or partially received payment keeps
its attempt and sends one staff alert linked to the invoice, after commit. Stripe cannot
safely replace it because the earlier funds have not been accounted for. Staff can run:

```sh
php artisan ae-stripe:resolve-charge INVOICE_ID --intent=pi_VERIFIED
php artisan ae-stripe:resolve-charge INVOICE_ID --no-charge-proof="Stripe request log or support evidence"
php artisan ae-stripe:resolve-charge INVOICE_ID --no-charge-proof="Original account request log confirms this key never reached Stripe" --checked-confirm-key=confirm_VERIFIED
php artisan ae-stripe:resolve-charge INVOICE_ID --accounted-proof="Verified refund or credit reference"
```

The first action validates invoice and customer ownership and resumes automatic checks.
It cannot swap out a different held intent; account for that payment first.
The second requires proof from the original Stripe account. With a known ID it also
requires a fresh 404 and refuses observed funds, processing debits or recorded payments.
A sent or unknown confirmation also refuses free-text proof alone. Staff must check the exact confirm key in the original account's request log and name it with `--checked-confirm-key`, only if the request never reached Stripe. The review Audit Log and refusal show the key; resolution audits that key, the original ID and the evidence. Known funds remain blocked even with that key.
A 404 or an empty search cannot establish that a confirm never arrived. The extension cannot read an inaccessible original account's request log, so staff must verify that evidence. `--accounted-proof`
requires a known succeeded or canceled intent and records staff evidence that its funds
were refunded or credited; late deliveries then cannot apply those funds again. Processing
intents cannot be released this way. Stripe cannot choose how to account for these funds,
so staff must verify that step. No refund or credit moves automatically. Known IDs keep retrying after access to the original
Stripe account is restored. Received funds still need the staff refund or credit choice.
Reconcile `ae_stripe_subscription_<service-id>` separately before removing an unresolved
schedule attempt; subscription behavior is unchanged by charge-attempt recovery.
Fakes do not
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
- First verify ConfirmationTokens with deferred Elements (`mode`, amount, currency), Customer Sessions and manual confirmation
  on pinned Basil, including card, Link, SEPA/ACH mandates, declines, 3DS and bank redirects.
  Check the client exclusions for ACSS, BLIK and customer balance; verify available methods before release.
  Check the bank/3DS error page and its invoice link. Check clearing method-specific future usage with `''` and preserving consent after authentication.
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

A successful checkout or bound subscription charge on an already paid invoice is
recorded as another successful transaction. The invoice stays paid; Paymenter does
not automatically create account credit. Refused successes and extra paid-invoice
charges retain one `ae_stripe_payment_review` invoice Audit Log entry and one staff
inbox alert per payment. Alerts run after the settlement commits; a durable pending
marker retries a failed alert on the next delivery without duplicating it. Check
Stripe and choose a refund or account credit; this needs a staff decision. No money
moves automatically. The audit identifies the intent, received amount, currency
and reason without customer details or client secrets.

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
label so a later retry can record its own method. First successful settlement
replaces any processing label with its own charge, even if the prior failure
webhook has not arrived. The first successful label
survives replayed success and late failure events. Link labels mask the email local
part. No card numbers, fingerprints or complete provider objects are stored.
Rendering never contacts Stripe. Earlier transactions are not backfilled.

With a pristine Paymenter release's dependencies available, also run
`PAYMENTER_TEST_APP=/path/to/pristine-release php tests/native-transaction-method.php`
to verify the migration, upgrade, unique key, foreign key and rollback on SQLite.

Optional transaction-method charge retrieval runs before settlement locks. Subscription PaymentIntent reads expand `latest_charge` before acquiring those locks. The locked handler rechecks customer, currency, invoice state and settled-payment replay before storing the label.

Checkout references (read for the deferred integration):
- https://docs.stripe.com/payments/finalize-payments-on-the-server?platform=web&type=payment
- https://docs.stripe.com/js/elements_object/create_without_intent
- https://docs.stripe.com/payments/payment-element/migration-ct
- https://docs.stripe.com/api/payment_intents/confirm
- https://docs.stripe.com/api/payment_intents/update
- https://docs.stripe.com/api/payment_intents/cancel

Offline Elements checks inspect options and use local Stripe.js stubs; they do not prove Stripe accepts a token on a persisted manual intent, CustomerSession tokens, saving options, token reuse, 3DS reconfirmation or redirects. Validate those combinations in test mode on the pinned API version before release.
