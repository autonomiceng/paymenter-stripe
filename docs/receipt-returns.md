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

The native user property `ae_stripe_receipt_<pi>` stores invoice, currency, original
customer, received amount and keyed allocations. Read/write uses user then invoice
locks. Keep the ledger permanently, including completed allocations. The positive
receipt remains; each completed allocation adds one negative native transaction
with its `key`, and a credit fallback is in that same database transaction.
Consumers calculating net native funding must include that negative transaction.

Recovery reconciles refund metadata before every send, with complete pagination
(up to our 1,000-refund inspection cap). Incomplete or foreign history, disputes,
access failures and expired unknown requests cannot produce a second refund or
credit. Restore API access or resolve the original payment in Stripe; an existing
matching refund will be recovered automatically. A missing old refund requires
Stripe request-log/support evidence before finance takes any external action.
Never clear a reservation to retry.

API references: [partial refunds](https://docs.stripe.com/api/refunds/create),
[refund listing](https://docs.stripe.com/api/refunds/list),
[idempotency lifetime](https://docs.stripe.com/api/idempotent_requests).
