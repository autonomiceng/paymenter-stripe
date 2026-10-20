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
The unchecked **Save this card for future payments** checkbox sets
`setup_future_usage=off_session` only after the owner submits consent. Unchecked
submission clears it. The save control is available only when the Element
reports a card or Link selection; changing to another method clears the opt-in. Stripe's own saving control is disabled to keep this one
consent path. Successful opted-in card/Link payments create/update one native
agreement per user, gateway and payment-method ID, serialized by user-row lock.
Account payment-method setup also authorizes saving and redisplay. No path
assigns an agreement to services or enables Auto Pay.

Only methods Stripe permits to redisplay appear. This fork sets
`allow_redisplay=always` after explicit checkout consent or account setup. Legacy
methods with unspecified consent may remain hidden: customers should reauthorize
in **Account > Payment methods**; no bulk consent conversion is included.
Removal in the Payment Element is disabled because native agreements may still
reference a method. Remove methods through Paymenter's account interface.

Link labels use Stripe's Link email, then its billing email, and otherwise
`Link`; no account-email substitution or invented card digits. Cards retain the
upstream brand, last four and expiry labels.

The disabled or missing fork acknowledges webhooks with 200 and a constant log
message, without inspecting payloads or calling Stripe. Enabled webhooks require
raw-body HMAC validation and timestamps within five minutes. Keep server time
synchronized. Successful transactions cannot be downgraded by delayed events.
