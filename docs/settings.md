# Settings and behavior

- **Stripe restricted API key**: encrypted server key. Grant the resource permissions
  below and confirm access in a test account. This fork pins `2025-07-30.basil`.
- **Stripe publishable key**: same account and test/live mode as the server key.
- **Send address and phone to Stripe**: off by default. Includes optional address,
  phone and company details for Stripe Tax and addresses on Stripe receipts. Customer creation failures stop checkout rather than
  falling back to an anonymous PaymentIntent.
- **Stripe webhook signing secret**: encrypted endpoint signing secret; never expose it
  in browser markup. CustomerSession and intent client secrets are user-scoped
  capabilities supplied only to the invoice owner.

## Stripe API permissions

All paths start with `/v1`. Read uses `GET`; write uses `POST` unless marked `DELETE`.
`{id}` is the resource ID. Migration account reads use both gateways' keys.

| Resource | Read | Write |
| --- | --- | --- |
| Accounts (migration) | `/account` | None |
| Balance transactions | `/balance_transactions/{id}` | None |
| Charges | `/charges/{id}` | None |
| Customer Sessions | None | `/customer_sessions` |
| Customers | `/customers/{id}` | `/customers`, `/customers/{id}` |
| Invoice Payments | `/invoice_payments` | None |
| Invoices | `/invoices/{id}` | `/invoices/{id}`, `/invoices/{id}/finalize`, `/invoices/{id}/pay` |
| PaymentIntents | `/payment_intents/{id}` | `/payment_intents`, `/payment_intents/{id}` |
| Payment methods | `/payment_methods/{id}` | `/payment_methods/{id}`, `/payment_methods/{id}/detach` |
| Products | `/products/search` | `/products` |
| SetupIntents | `/setup_intents/{id}` | `/setup_intents`, `/setup_intents/{id}` |
| Subscription items | None | `/subscription_items/{id}` |
| Subscription schedules | `/subscription_schedules/{id}` | `/subscription_schedules`, `/subscription_schedules/{id}`, `/subscription_schedules/{id}/release` |
| Subscriptions | `/subscriptions/{id}` | `/subscriptions/{id}`, `DELETE /subscriptions/{id}` |
| Webhook endpoints | `/webhook_endpoints` | `/webhook_endpoints`, `DELETE /webhook_endpoints/{id}` |

The Payment Element displays Stripe-eligible saved cards, wallets and Link.
The **Save this card for future payments** checkbox starts unchecked; checking it sets
`setup_future_usage=off_session` only after the owner submits consent. Unchecked
submission clears it. The save control is available only when the Element
reports a card or Link selection; changing to another method clears the opt-in. Stripe's own saving control is disabled to keep this one
consent path. Successful opted-in card/Link payments create/update one native
agreement per user, gateway and payment-method ID, serialized by user-row lock.
Account payment-method setup also authorizes saving and redisplay. Customer setup
and checkout never assign an agreement to services or enable Auto Pay.

Only methods Stripe permits to redisplay appear. This fork sets
`allow_redisplay=always` after explicit checkout consent or account setup. Legacy
methods with unspecified consent may remain hidden: customers should reauthorize
in **Account > Payment methods**. The optional [migration redisplay step](migrating-from-bundled.md)
covers only adopted bundled methods with attested explicit setup consent; it does
not infer consent for other legacy imports.
Removal in the Payment Element is disabled because native agreements may still
reference a method. Remove methods through Paymenter's account interface.

Link labels use Stripe's Link email, then its billing email, and otherwise
`Link`; no account-email substitution or invented card digits. Cards retain the
upstream brand, last four and expiry labels.

A disabled or missing fork validates the signature, then answers 503 with a constant
log message, without parsing payloads or calling Stripe; Stripe retries the event. Enabled webhooks require
raw-body HMAC validation and timestamps within five minutes. Keep server time
synchronized. Successful transactions cannot be downgraded by delayed events.
