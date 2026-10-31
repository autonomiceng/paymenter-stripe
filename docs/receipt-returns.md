# Shared receipt returns

```php
$gateway->allocateReceiptReturn($invoice, $paymentIntentId, $allocationKey, $amountMinor);
$gateway->receiptReturnStatus($invoice, $paymentIntentId, $allocationKey); // array or null
```

Use the configured AE Stripe gateway instance. These are internal service methods,
not customer HTTP endpoints. Amounts are positive integer Stripe minor units in the
receipt's currency. `excess` is reserved for automatic overpayment returns. Other
callers use stable namespaced keys, such as `domains:failed:123:pi_ABC`. Retry the
same key with the same amount. A changed amount is refused. A genuinely new
incremental entitlement gets a different key; keys are at most 180 bytes.

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

API references: [partial refunds](https://docs.stripe.com/api/refunds/create),
[refund listing](https://docs.stripe.com/api/refunds/list),
[later refund failures](https://docs.stripe.com/refunds#failed-refunds),
[idempotency lifetime](https://docs.stripe.com/api/idempotent_requests).
