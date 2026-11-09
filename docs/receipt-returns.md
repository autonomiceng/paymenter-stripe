# Shared receipt returns

```php
$gateway->allocateReceiptReturn($invoice, $paymentIntentId, $allocationKey, $amountMinor);
$gateway->receiptReturnStatus($invoice, $paymentIntentId, $allocationKey); // array or null
$gateway->receiptReversals($invoice, $paymentIntentId); // observation arrays, including cleared items
```

Use the configured AE Stripe gateway instance. These are internal service methods,
not customer HTTP endpoints. Amounts are positive integer Stripe minor units in the
receipt's currency. `excess` is reserved for automatic overpayment returns. Other
callers use stable namespaced keys, such as `domains:failed:123:pi_ABC`. Retry the
same key with the same amount. A changed amount is refused. A genuinely new
incremental entitlement gets a different key; keys are at most 180 bytes.
`stripe:` keys are reserved for gateway observations, not return allocations.

All allocations share one immutable receipt budget. Combined allocations cannot
exceed `received_minor`, including reserved and pending amounts. AE Stripe owns
refund requests, retry keys, recovery, return transactions and credit fallback.
Callers must prove the failed invoice line and its return entitlement, net prior
refunds/credits outside this ledger, allocate it across positive receipts, and
retire/correct the failed obligation. Do not independently create credit or negative
transactions for an allocation handled here. This API does not infer line ownership
or refund another gateway's receipts. A verified pre-upgrade AE Stripe payment can
be adopted on explicit allocation; a foreign refund prevents another return.

Each result includes `state`, `amount_minor`, `key`, `sent_at` and `refund_id` (null
until known). `credit_id` identifies the fallback credit when completed. States:

| State | Caller action |
| --- | --- |
| `reserved` | Wait for the caller's transaction to commit; money I/O runs after commit. |
| `pending` | Refund is still open. Wait; do not credit or send another refund. |
| `refunded` | Original-method return completed. Record the returned allocation. |
| `credited` | Refund definitively failed or was canceled; credit and native adjustment committed together. |
| `review` | Read `reason` and follow the invoice Audit Log/Stripe resolution path. No proof of no refund. |

Reservation is safe inside an existing transaction. After its commit, recovery may
complete before the method returns; inspect the returned state. Rollback discards
the reservation without refund I/O. Backend callers treat a 409 as a refused
allocation, never as permission for independent credit.

The extension's install/upgrade hook creates `ae_stripe_receipt_returns`: one row
per allocation, unique on receipt/allocation, with a state/retry/time index for due
work. Immutable invoice, owner, customer, currency and receipt amounts accompany
each row. User then invoice locks serialize reservations and completion. Keep
completed rows permanently. Nothing is stored in the native profile properties.
Ordinary receipts retain their positive transaction; each completed allocation
adds one deterministic negative transaction. Credit and its adjustment commit
together; the negative credit adjustment has `is_credit_transaction = true`,
while a cash refund has `false`. Include both in net invoice funding.
If an owned cash refund later fails, the existing return row is locked and checked
against its invoice, gateway, succeeded status and exact allocation amount. Only
its credit-classification flag changes, without model events or timestamp changes.
Initial ordinary return posts retain native events and paid-status behavior.

A successful checkout or exactly bound subscription payment on a closed invoice
reserves its whole receipt as `excess`.
Core's normal `addPayment` can mark a cancelled invoice paid and activate it, so
this receipt stays outside succeeded native funding until return completion. Then
+receipt/-return post atomically with transaction events suppressed. The invoice
stays closed. Domains sees no new settled funding while pending and zero net
funding afterward; this automatic allocation owns the whole receipt, so Domains
must not independently credit or refund it.

Closed subscription receipts retain their Stripe subscription-invoice ID in the
allocation row. Recovery re-reads that paid invoice, verifies its local binding,
customer, currency and paid amount, then checks its complete payment list contains
exactly the retained PaymentIntent and amount. Subscription intents do not need
checkout `metadata.invoice_id`; checkout receipts still require that binding.
Unbound, changed, split or unreadable subscription funding remains in review with
a resolution path. No weaker fallback to a guessed invoice is used.

Checkout-attempt retirement deletes only `ae_stripe_checkout_<invoice>` after
settlement/review. The extension allocation, first-send marker and later recovery
remain intact; retiring the form cannot discard a pending closed-invoice return.

Owned `refund.failed` and `refund.updated` events reconcile canonical Stripe
history under the same locks. A refund can fail after a succeeded notice: proved
failure becomes credit once, updating the existing adjustment identity and credit
classification. Disputed outcomes are held for reconciliation. A completion notice
is due until `notified` matches the current state/reason, even after a prior pending
notice. The five-minute sweep reads at most 100 indexed due allocations and stops
its batch on a connection or authentication failure.

Recovery reconciles refund metadata before every send, with complete pagination
(up to our 1,000-refund inspection cap). Incomplete or foreign history, disputes,
access failures and expired unknown requests cannot produce a second refund or
credit. Restore API access or resolve the original payment in Stripe; an existing
matching refund will be recovered automatically. A missing old refund requires
Stripe request-log/support evidence before finance takes any external action.
Never clear a reservation to retry.

## Automatic overpayment returns

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

A successful checkout or exactly bound subscription payment on a closed invoice
returns the whole receipt automatically.
Until completion it has no new succeeded native funding. Completion posts the
+receipt/-return pair atomically without native transaction events, preserving the
closed invoice and preventing activation. Verify with native observers and the
combined stale-form authentication/cancellation flow. Domains' late-money scan sees
no new settled funding while pending and zero net funding afterward; AE Stripe's
`excess` allocation owns the receipt. Check both Domains scans before and after the
return, with duplicate webhook, return-page and scheduler deliveries.
For subscriptions, omit checkout metadata from the PaymentIntent. Interrupt the
first recovery, then run only the receipt sweep: it must re-read the stored Stripe
invoice and its complete paid-payment list, verify binding/customer/currency/amount,
and return the whole receipt once. Changed binding or unrelated payment membership
must stop without a refund or credit. Retiring the checkout property must preserve
the return allocation and its recovery markers.

External refunds, disputes, incomplete history and expired unknown sends surface
an invoice-linked problem and resolution path. Access problems keep retrying;
neither unknown refunds nor a 409 allows another refund or independent credit.
Customer/currency conflicts still use payment review because ownership cannot be
proved. Historical excess is not inferred from today's balance. The
shared receipt-return API above can adopt a verified historical
native receipt for a caller's proved entitlement. Refunds created in the Stripe
Dashboard are not coordinated with this flow; Dashboard actions are not serialized
with API reads.

## Refund and dispute observations

Dashboard refunds (`refund.created`, `refund.updated`, `refund.failed`) and disputes
(`charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`) are
recorded in the same receipt ledger under `stripe:refund:<id>` or
`stripe:dispute:<id>`. These rows do not consume allocation budget and never enter
the money-return worker. Owned return refunds keep their existing reconciliation.
Foreign refund history still prevents another return, even if that refund failed.

Each signed delivery retrieves the current Stripe object under the customer and
invoice locks. It must match the succeeded native AE Stripe receipt or an existing
closed-invoice receipt, its owner, Stripe customer, currency and received amount.
Charge-only disputes resolve through their charge's PaymentIntent. Events arriving
before an expected local settlement return a retryable error. Unrelated gateway
payments are ignored. A failed read, ledger write or staff notification rolls back
and propagates a non-2xx response so Stripe can retry; the original payment stays
recorded. Duplicate state creates no repeated audit or alert; stale open events
cannot reopen a won, lost or cleared dispute, including stale inquiry events. No invoice status, service, transaction or
credit changes are made by observation.

For invoice page/PDF display, use `$gateway->invoiceReversalHistory($invoice)` after
authorizing access to the invoice. This uses unlocked local SELECTs only: no Stripe
calls, schema installation, payment lock or writes. It works when the gateway is
disabled and returns an empty array before the ledger is installed. Results are
scoped to the stored invoice, owner and currency, with `kind`, `id`, `status`,
`payment_intent_id`, `received_minor`, `amount_minor`, `amount` (major units),
`currency`, `provider_created_at` and `recorded_at` (epoch seconds).
`provider_created_at` is Stripe's creation date, stored once from its refund/dispute
response. Later status changes never move it. Same-state observations fill missing
dates without another audit/notice. Owned refund responses also supply this date.
An additive nullable-column migration runs on upgrade or before a writer needs it;
the reader installs nothing and tolerates the old schema. Display the provider
date when present; otherwise use `recorded_at`, labelled “Recorded.” The latter is
the last ledger observation time, not Stripe's creation date.
Owned refund allocations with a Stripe refund ID are included, deduplicated against
observations; an allocation that fell back to credit shows a failed cash refund.
Count only refund rows with `status=refunded` in the amount refunded. Disputes and
pending/failed refunds do not belong in that total. This history is never proof
that Stripe has no other reversal. Keep its internal IDs off customer displays.

`invoiceReversalSummary($invoice)` returns that same history as `rows`, gross cash
`totals` in major units and an `overlap` flag. It reads local succeeded, positive,
non-credit AE Stripe PaymentIntent transactions, including payments without
reversals, and uses the stored canonical `received_minor` for observed payments.
For each payment independently it deducts completed refunds, then open disputes,
then lost disputes, each bounded by the unreturned receipt remainder. Adding those
per-payment results gives Refunded, Open disputes, Lost to disputes and Net received
(before Stripe fees). Table rows retain their full observed amounts: a partial
refund followed by a full lost dispute must not deduct the refunded part twice.
An inquiry takes no money; won/cleared disputes and pending/failed/review rows
deduct zero. A refund plus any dispute other than won/cleared on the same payment
sets `overlap`: show “Refund and dispute on the same payment. Check the balance in
Stripe.” This is a stored gross cash summary, not a fee calculation or accounting
journal. Customer refunds and their completed-only total stay separate.

The lock-taking `receiptReversals()` interface is for the configured gateway instance
and one local invoice/receipt. It returns `[]` when nothing has been observed,
never proof that no reversal exists in Stripe. Each result has:

| Field | Meaning |
| --- | --- |
| `kind`, `id` | `refund`/`dispute` and its Stripe ID; deduplicate consumers on receipt/kind/ID. |
| `status` | Refund: `open`, `refunded`, `failed`. Dispute: `open`, `won`, `lost`, `cleared` (closed warning), `inquiry` (`warning_needs_response` / `warning_under_review`, no money taken). |
| `needs_review` | False for proved clean completed refunds, failed/canceled refunds, won disputes, closed warnings and a current audited staff resolution. |
| `invoice_id`, `user_id`, `customer`, `payment_intent_id`, `currency` | Verified local and Stripe receipt identity. |
| `amount_minor` | Refund/dispute amount, not an amount to debit. |
| `credited_minor` | Completed credit allocations for this receipt in this ledger. |
| `credit_balance_minor` | Current pooled customer credit in the invoice currency. |
| `next_step` | Staff action line from the last observed status change. |

Inquiries still need review and hold automatic returns, as they did when labelled
open; no new hold or staff action is added. A final dispute cannot regress to open
or inquiry. Inquiry status does not represent a cash deduction.

Finance work uses the invoice Audit Log (`ae_stripe_receipt_reversal`) and the
existing native staff inbox, with an invoice link and one next-step line. Open
disputes ask staff to submit evidence in Stripe before its deadline; lost disputes
and uncertain external refunds ask staff to reconcile Stripe with invoice/credit history.
The line includes the reversal amount, customer, receipt credit and current
account balance, including zero after credit has been spent. A won dispute clears
`needs_review` and posts one resolution audit/alert; older inbox messages remain
history. The caller must read current status rather than treating an old alert as
an open work item.

Pooled credit cannot identify which receipt's credit was spent. These amounts are
context, never an inferred debit or recoverable debt. Credits made outside this
ledger need the caller's own attribution. The later Domains consumer must combine
these observations with its recorded credits/obligations, and route proven repair
to staff; it must not issue another credit or guess a debit. No automatic debit,
service deletion, or refund on a dispute is provided here.

The existing five-minute receipt-return sweep reconciles event subscriptions after
upgrade, retrying until successful; no gateway save is needed. It merges required
events onto one existing endpoint after checking account/mode and a complete list
within our 100-endpoint inspection cap. It never creates/deletes endpoints or changes their URL,
version, signing secret, credentials or retained-secret overlap. Missing, ambiguous,
changed or unverified endpoints and refused requests remain untouched; the gateway
page shows one line to check the endpoint/API permissions and save AE Stripe.
Saving remains the manual setup path, including complete paginated inspection.
Fresh installs subscribe at creation. No missed-event backfill is performed.
Keep signing secrets under the overlap rules in
[verification](verification.md#webhook-continuity).

API references: [partial refunds](https://docs.stripe.com/api/refunds/create),
[refund listing](https://docs.stripe.com/api/refunds/list),
[later refund failures](https://docs.stripe.com/refunds#failed-refunds),
[idempotency lifetime](https://docs.stripe.com/api/idempotent_requests).

## Staff review state

Review is separate from canonical Stripe status and every money-return guard.
Completed refunds clear automatically when stored receipt evidence has no credit,
unsettled return, unresolved dispute, conflicting refund amount or over-return.
Receipt credit remains a conflict even after its pooled balance reaches zero.
Native invoice credit transactions also prevent automatic clearance. Failed/cancelled
refunds, won disputes and closed inquiries continue to clear themselves.

`invoiceReversalReviews($invoice)` is an unlocked, local-only display read, keyed by
`refund:<id>` / `dispute:<id>`. Authorize the invoice before calling it. It returns
`needs_review`, `review_status` (`cleared`, `resolved`, `needs_review`) and `review_key`.
`receiptReversals` includes the same fields under its existing owner/invoice locks.

When Stripe cannot prove spent-credit attribution or an unsettled outcome, staff
with invoice view and update permissions may call
`resolveReceiptReversal($invoice, $paymentId, $stripeId, $kind, $reviewKey, $note)`.
The required note (at most 1,000 bytes), actor and complete Stripe ID are stored in
`ae_stripe_reversal_resolved` on the invoice Audit Log. Duplicate submissions are
idempotent. A stale key returns 409; reload and check the changed evidence.
New receipt/allocation/dispute or invoice-credit evidence reopens review.
Allocation retry/notification timestamps alone do not reopen an unchanged case.
This writes only an audit entry: no refund, debit, invoice status, canonical reversal
state, ledger entry or receipt hold is changed. Existing inbox messages remain history.
