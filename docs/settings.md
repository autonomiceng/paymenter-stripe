# Settings and behavior

- **Stripe Restricted key**: encrypted server key. Permit the upstream customer,
  PaymentIntent, SetupIntent, payment-method, balance-transaction and webhook
  operations, plus CustomerSession creation and payment-method updates. Confirm
  exact permissions in a test account; this fork pins `2025-07-30.basil`.
- **Stripe Publishable Key**: same account and test/live mode as the server key.
- **Create detailed Stripe customers**: includes optional address, phone and
  company properties. Customer creation failures stop checkout rather than
  falling back to an anonymous PaymentIntent.
- **Stripe webhook secret**: encrypted endpoint signing secret; never expose it
  in browser markup. CustomerSession and intent client secrets are user-scoped
  capabilities supplied only to the invoice owner.

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
