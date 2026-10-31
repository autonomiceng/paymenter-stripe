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
cannot reopen a won or lost dispute. No invoice status, service, transaction or
credit changes are made by observation.

The read-only `receiptReversals()` interface is for the configured gateway instance
and one local invoice/receipt. It returns `[]` when nothing has been observed,
never proof that no reversal exists in Stripe. Each result has:

| Field | Meaning |
| --- | --- |
| `kind`, `id` | `refund`/`dispute` and its Stripe ID; deduplicate consumers on receipt/kind/ID. |
| `status` | Refund: `open`, `refunded`, `failed`. Dispute: `open`, `won`, `lost`, `cleared` (closed warning). |
| `needs_review` | False for failed/canceled refunds, won disputes and closed warnings; true otherwise. |
| `invoice_id`, `user_id`, `customer`, `payment_intent_id`, `currency` | Verified local and Stripe receipt identity. |
| `amount_minor` | Refund/dispute amount, not an amount to debit. |
| `credited_minor` | Completed credit allocations for this receipt in this ledger. |
| `credit_balance_minor` | Current pooled customer credit in the invoice currency. |
| `next_step` | Staff action line from the last observed status change. |

Finance work uses the invoice Audit Log (`ae_stripe_receipt_reversal`) and the
existing native staff inbox, with an invoice link and one next-step line. Open
disputes ask staff to submit evidence in Stripe before its deadline; lost disputes
and external refunds ask staff to reconcile Stripe with invoice/credit history.
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
