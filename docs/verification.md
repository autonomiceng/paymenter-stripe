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
parameters before HTTP and never confirms or supplies a card. Elements attempts use default
automatic confirmation; native saved-method and bank-debit attempts use manual confirmation.
The rendered checkout never includes the PaymentIntent client secret. Only a locked confirm
result requiring authentication returns it. Opening checkout only prepares the form. Elements uses deferred `mode: payment` with the intent's integer amount and lowercase currency,
plus manual payment-method creation. BLIK and ACSS are excluded because this integration path does not support them; this form also excludes customer balance.
Ilya checked creation in Stripe test mode on 2026-10-30 at 12:00 PM PT (2025-07-30.basil): manual confirmation alone returned 200; adding automatic_payment_methods returned 400.
The live pass on 2026-10-30 then proved Elements tokens are rejected on manual intents.
Elements now uses the documented automatic flow; its real Stripe acceptance must be checked again.
Klarna, Cash App, Link and bank methods remain available under the same exclusions.
Submission sends a ConfirmationToken to the authenticated,
CSRF-protected confirm endpoint. Under user then invoice locks, the server retrieves
the intent, rechecks the balance, customer and active saved agreement, updates the amount,
and confirms. Locks remain held through confirmation to keep the balance and card checks valid.
Every Stripe request has a five-second timeout and a two-second connection timeout;
the four-call confirmation section is bounded by those individual request limits.
A Stripe timeout stays pending. After a saved instruction commits, statement errors and raw PDO commit errors also stay pending for automatic recovery, without a failed-payment email. A first-lock failure before reservation surfaces as a failure because nothing was saved to retry. Paused saved charges are reserved without HTTP and resume when the gateway is enabled. A changed checkout
amount requires a refresh because the customer has not agreed to the new total.
Elements 3DS uses `handleNextAction` and completes without another server confirmation.
The first confirm rechecks the balance; if the invoice changes after authentication starts,
the original amount can complete and any excess is automatically returned through the receipt ledger.
Failed Elements authentication is canceled on the next page load, return or failure webhook,
after retrieving the current status; a fresh attempt then permits another payment.
Native manual authentication and bank verification still reconfirm under the server checks. Card/Link saving consent remains explicit and method-specific.
The GET payment return compares Stripe's redirect secret before any write. Missing or wrong secrets show read-only status with an invoice link; the webhook, recovery or authenticated payment form can still finish it.

A lost confirm response is recovered by intent ID, beyond key expiry. An unconfirmed
creation can be abandoned after 23 hours because it cannot have taken money. No frozen
confirmation is replayed. Native bank verification that finishes later is polled and reconfirmed without replaying a consumed token. Automatic Elements payments are read after authentication, not reconfirmed.
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
Known, unfunded automatic Elements payments awaiting authentication are checked hourly because only the customer can authenticate. Resume evidence and recovery stay enabled so a missed webhook still settles later. Manual bank verification, new unknown confirmations and money-bearing outcomes keep normal recovery.
A definite create refusal clears a no-ID creation and tells the customer how to pay. A 401/403 retains the unsent instruction; recovery resumes after access is fixed. Refused reads also retain recovery. A repeated refusal stops the batch and alerts staff once per configured key across invoices, after commit; only staff can restore Stripe access. Lookup errors never prove a charge failed.
Currency and card refusals explain the reason and permit another method or a repaired setup.
Cancellation requests have no persistent failure-cache key; replacement still needs a
Stripe-confirmed cancellation with no received funds.

Charge-attempt recovery needs no schema migration. Receipt returns have their own extension migration. Existing known IDs are retrieved. Existing unfinished
automatic intents without the new durable `elements` marker are canceled before replacement, invalidating old tabs. Unconfirmed manual checkout intents are canceled before changing to Elements; existing native authentication stays manual. Switching to native Pay cancels an unfinished Elements intent before creating a manual replacement. Concurrent callers follow the current reservation after a proven replacement. Processing
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
A sent or unknown confirmation also refuses free-text proof alone. Staff must open the named payment in the original account's Workbench Inspector > Logs and compare the confirm key in request details. Logs must cover the attempt date onward; missing or aged-out logs prove nothing. If they cannot establish that the request never reached Stripe, obtain Stripe support proof covering that account, payment, key and dates. Only then name the key with `--checked-confirm-key`. The review Audit Log and refusal show the key; resolution audits that key, the original ID and the evidence. Known funds remain blocked even with that key.
No-charge release ends the saved instruction and sends the customer an invoice pay link; it never automatically re-debits on a person's evidence. Requires-action resume records keep the sent confirm key.
A 404 or an empty search cannot establish that a confirm never arrived. The extension cannot read an inaccessible original account's request log, so staff must verify that evidence. `--accounted-proof`
requires a known succeeded or canceled intent and records staff evidence that its funds
were refunded or credited; late deliveries then cannot apply those funds again. Processing
intents cannot be released this way. Stripe cannot choose how to account for these funds,
so staff must verify that legacy reconciliation step. Known IDs keep retrying after access to the original
Stripe account is restored. New, validated excess receipts use the automatic return flow below.
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
- First verify ConfirmationTokens with deferred Elements (`mode`, amount, currency), Customer Sessions and automatic confirmation
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
recorded in full. Each validated excess reserves one row in the extension-owned
`ae_stripe_receipt_returns` table, in the settlement transaction. Install/upgrade
creates the table; native profile properties contain no receipt history. After
commit, AE Stripe returns the excess to the original method. The five-minute
`ae-stripe:receipt-returns` task selects up to 100 indexed due allocations, including
completed returns whose current notice has not been delivered, and stops on a
connection or authentication failure. Refund read/write access is required.

Each immutable allocation has a payment/allocation/amount-bound idempotency key.
Recovery verifies customer, currency, dispute status and complete refund history
before sending. A first-send marker commits before HTTP. Matching refund metadata
recovers a lost response; unknown sends are not replayed after our 23-hour boundary
because Stripe may expire keys after 24 hours. Pending refunds remain reserved.
Only a proved failed/canceled refund on an undisputed receipt becomes account
credit. `refund.failed`/`refund.updated` also recover a failure after a succeeded
notice, keeping the same native adjustment identity. Saving gateway settings adds
these subscriptions to an existing endpoint in place; verify this on upgrade.

Ordinary receipts retain their full positive native transaction. Completion adds
one keyed negative transaction, marked as a credit transaction only for credit
fallback. Credit, adjustment and completion commit atomically. Audit entries and
customer notices show progress, completion or a problem; notification retries
compare against the current state/reason, so a prior pending notice cannot hide a
missing completion notice. Exercise both pending-to-refunded and pending-to-credit
with notice insertion interrupted after settlement, then recover by the sweep alone.

A successful checkout on a closed invoice returns the whole receipt automatically.
Until completion it has no new succeeded native funding. Completion posts the
+receipt/-return pair atomically without native transaction events, preserving the
closed invoice and preventing activation. Verify with native observers and the
combined stale-form authentication/cancellation flow. Domains' late-money scan sees
no new settled funding while pending and zero net funding afterward; AE Stripe's
`excess` allocation owns the receipt. Check both Domains scans before and after the
return, with duplicate webhook, return-page and scheduler deliveries.

External refunds, disputes, incomplete history and expired unknown sends surface
an invoice-linked problem and resolution path. Access problems keep retrying;
neither unknown refunds nor a 409 allows another refund or independent credit.
Customer/currency conflicts still use payment review because ownership cannot be
proved. Historical excess is not inferred from today's balance. The
[shared receipt-return API](receipt-returns.md) can adopt a verified historical
native receipt for a caller's proved entitlement. Dashboard refund coordination
belongs to P05; this change does not serialize Dashboard actions with API reads.

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
invoice before applying money. Refund events for owned receipt allocations are handled; other refund and dispute reconciliation remains outside this flow.
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
to verify migration, upgrade, unique key, foreign key and rollback on SQLite. Run
`PAYMENTER_TEST_APP=/path/to/pristine-release php tests/native-receipt-returns.php`
for independent allocation rows beyond the old aggregate TEXT limit, due-work
indexing, native closed-invoice event suppression and cash/credit classification.
These tests use disposable in-memory SQLite, never the installed application database.

Optional transaction-method charge retrieval runs before settlement locks. Subscription PaymentIntent reads expand `latest_charge` before acquiring those locks. The locked handler rechecks customer, currency, invoice state and settled-payment replay before storing the label.

Checkout references (read for the deferred integration):
- https://docs.stripe.com/payments/finalize-payments-on-the-server?platform=web&type=payment
- https://docs.stripe.com/js/elements_object/create_without_intent
- https://docs.stripe.com/payments/payment-element/migration-ct
- https://docs.stripe.com/api/payment_intents/confirm
- https://docs.stripe.com/api/payment_intents/update
- https://docs.stripe.com/api/payment_intents/cancel

Offline Elements checks use local Stripe.js and HTTP fixtures. They do not prove Stripe accepts the new automatic token flow, CustomerSession tokens, saving options, 3DS completion or redirects. A fixture rejects the manual/Elements pairing using the observed live error; it is not provider acceptance evidence. Validate those combinations in test mode on the pinned API version before release.

## Retire open payment forms before credit

`AeStripe::cancelInvoicePayments($invoice): bool` cancels the invoice's still-payable
PaymentIntent. It returns `false` when money is processing, needs capture, or just
settled; leave credit unapplied and refresh the invoice after settlement. Exceptions
also mean do not apply credit. No processing payment is cancelled. Successes use the
existing settlement/late-money handler.

For an explicit credit action, lock the user then invoice in the same database
transaction as spending credit, call the gateway, and apply credit only on `true`:

```php
$applied = DB::transaction(function () use ($invoice, $gateway) {
    User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
    $current = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
    if (ExtensionHelper::call($gateway, 'cancelInvoicePayments', [$current]) !== true) return false;
    // Re-read the remaining balance and apply the requested credit here.
    return true;
});
// On false, tell the customer payment is being checked and refresh; do not spend credit.
```

`$gateway` is the native `App\Models\Gateway` row for `AeStripe`. This is a server
integration call, not a customer route. Landing owns integrating it into Apply credit.
Domains needs no Stripe dependency: normal Eloquent invoice status updates dispatch
Paymenter 1.5.9's `App\Events\Invoice\Updated`, and the gateway retires forms after
commit. Invoice closure is not gated on Stripe access: an outage can leave an old
3DS tab able to complete before cancellation recovery. A late success must leave the
invoice closed and reach the late-money handler. The integrated receipt-return
ledger must return those funds automatically once, without activating the order.
Bulk SQL updates do not dispatch that event. The five-minute recovery job
also scans checkout-only attempts, including `retry=false`, and retries cancellation
when Stripe returns. Partial-credit changes retire checkout forms too. Call before
credit rather than relying on that later sweep to protect an already open tab.

## Webhook continuity

Before the first credential rotation after upgrade, save the existing working keys
and signing secret once. Each save verifies `GET /v1/account` and the paginated
webhook list. Keep the same Stripe account and test/live mode; API-key replacement
is allowed on that account. The restricted key needs account-read and endpoint-read
permissions plus endpoint-write when creating or updating setup. Use the account's
matching publishable key; Stripe's account read does not prove a publishable key's
account identity.

The gateway stores its checked account, endpoint, API credentials and saved signing
secrets in one native encrypted setting, omitted from the configuration form. A
failed account/endpoint check restores the previous accepted credentials. Runtime requests refresh
that setting so an in-flight handler can use a replacement API key. Keep API keys
valid until their in-flight HTTP requests finish.

Roll a signing secret in Stripe, then save the replacement on the gateway. The old
secret stays accepted **without expiry until a delivery validates with the saved
replacement**. That delivery starts its **72-hour overlap**; later saves and deliveries
do not extend the deadline. A typo or stale admin form therefore cannot time out the
secret Stripe is still using. Signature timestamps retain the five-minute replay check. This covers
Stripe's [three-day automatic delivery retry window](https://docs.stripe.com/webhooks#automatic-retries).
Do not log, copy into reports, or screenshot signing secrets or API keys.

Existing endpoints are reused, including the legacy StripeSaved route. URL changes
update the same endpoint; pending deliveries, API version, enabled/disabled status
and existing event subscriptions remain. The [endpoint update API](https://docs.stripe.com/api/webhook_endpoints/update)
supports URL and event edits; API version is only set when creating a new endpoint.
An existing endpoint with a missing local secret is preserved and asks for its secret
from Stripe, since Stripe's list API cannot recover it. No endpoint is deleted.
Cutover still follows the paused-writer and missed-event reconciliation steps in
[migration](migrating-from-bundled.md); this does not redirect the bundled core route
or enable a disabled endpoint.
