# Auto Pay integration

Saving a payment method and enabling Auto Pay are separate actions. Auto Pay is one
account switch: on, every recurring service, current and new, renews with the account
card; off, none do. Paying an invoice never turns it on. Customer and staff views use
the same words.

## Account switch (theme integration)

Resolve AE Stripe through `ExtensionHelper::getExtension('gateway', 'AeStripe')`.
Read `autoPayDefault($user)`: null means Off; otherwise `agreement_id` identifies the
account card, a native BillingAgreement. Read `autoPayCard($user)` for the chosen numeric
agreement ID while Off as well as On. This preference grants no charging consent.
Do not derive consent from boolean properties or old Stripe metadata.

Show an Off switch for Auto Pay. Turning it on is the consent: confirm what it covers in
one line, for example “Renewals for all 3 services charge Visa **** 4242.”, and keep the
configured Terms link in its help. With one owned saved method the switch uses it; with
more, retain their saved choice. With no choice yet, use the oldest saved method so
adding a new card or Link cannot silently replace the displayed card. Open invoices are not
charged by turning it on; offer Pay now for those.

To pay an open invoice the customer accepted with a saved method, call
`chargeAccepted($invoice, $amount, $agreement)` with the amount they saw. It is the
saved-method charge with an accepted amount stored in the payment reservation before
Stripe requests. Confirmation refuses a different balance under the payment lock
("The amount due changed."), including after interrupted creation or replacement.
For the account Auto Pay open-invoice offer, use
`chargeAutoPayAccepted($invoice, $amount, $agreement)`. It also checks the account
Auto Pay card while reserving and confirming the payment. Both transactions lock
the user first, as does changing or disabling Auto Pay.
`charge()` keeps confirming today's balance for renewals and recovery instructions
that have no accepted amount. Themes must require `chargeAutoPayAccepted()` before
offering account Auto Pay invoice payments.

POST JSON to `route('extensions.gateways.ae-stripe.auto-pay-default')` with the normal
session, Accept application/json and X-CSRF-TOKEN:

```json
{"enabled":true,"agreement_id":123,"consent":true,"version":"auto_pay_account_v1"}
```

`agreement_id` is the native numeric ID, not the Stripe reference or ULID. The account
scope requires fresh authenticated acceptance even for an existing saved card; the older
new-services-only `auto_pay_new_services_v2` record grants nothing new. Off: `{"enabled":false}`.
To save only the card preference, post `{"agreement_id":123}` without `enabled`.
This checks ownership and saves the choice without changing any account or service
charging consent. On always requires the full fresh consent request above.
A 200 returns `ok`; switch requests also return `enabled` and, when on,
`services` (how many were linked). Preserve the
control on errors. No default is inferred from setup completion.

The property `ae_stripe_auto_pay_default` records version, user ID, server acceptance
time and agreement ID. On links every recurring, uncancelled service of the user to the
card, under the user lock; off unlinks every service and keeps the card choice in
`ae_stripe_auto_pay_card`, separately from charging consent. Stripe subscriptions keep their own
billing either way. Native Service Created applies the record to new recurring services
without a selected agreement or subscription; Service Updated never reapplies it.
Linking charges nothing: native renewal charges happen when an invoice is issued, so open
invoices stay for the customer to pay. On and Off both stop a renewal retry waiting on an
open invoice, so the confirm can say open invoices are not charged. Removing the account card
deletes its consent and card choice and unlinks the services it paid for, so Auto Pay reads Off.

Services linked one at a time before the account switch keep their card and still renew
with it, with or without a record. Show the switch as On while any service is linked, naming
the card of the newest link, and say so when links use different cards. Off unlinks them too.

## Dates for every service opt-in

Call `autoPayDates($service)` before showing either a customer or staff Auto Pay
choice, including the service modal and domain page. Call
`invoiceAutoPayDates($invoice)` for the invoice's “Also turn on Auto Pay for the
recurring services” choice. It returns a map keyed by owned recurring service ID.
The extension checkout also passes this map as `$autoPayDates` to its pay view.
These are service preferences, even when selected from an invoice page.

All dates are Y-m-d in the billing calendar. The array contains `service_id`,
`invoice_on`, `charge_on`, `charge_timing: invoice_creation` and `state`:

- `scheduled`: show both dates, e.g. “Invoice Nov 24. Auto Pay Nov 24.” Hosting uses
  native `settings.cronjob_invoice` (default seven days); Domains uses its owner-scoped
  NativePresenter/DomainSummary `renewalIssueAt`, never the due date or hosting offset.
- `next_billing_run`: `invoice_on` is the overdue calendar date and `charge_on` is null.
  Say “Invoice and Auto Pay at the next billing run.” A scheduler's future run time
  cannot be promised from a date-only calendar.
- `existing_invoice`: `invoice_id` and `invoice_on` identify an existing native unpaid
  invoice; a retained Domains invoice provides `invoice_url` instead (its public
  DTO has no issue date). `charge_on` is null. Say “Pay the open invoice. Auto Pay applies to future
  renewals.” Turning on Auto Pay does not submit a debit for that invoice.
- `not_scheduled`: no renewal charge is scheduled (inactive, free, cancelled or renewal
  stopped). Newly ordered services can acquire a default before their calendar exists.
- `missing`: the service should have a calendar but does not. Show “Missing” with a
  link to the service/support so it can be fixed; never invent a charge date. An older
  Domains DTO without `renewalIssueAt` also yields Missing.

These methods read local models only and do not create invoices or charge. Retained
Domains renewal lines have no native service reference and are deliberately absent
from the native invoice recurring-service selector; use the domain/service page for
its Auto Pay control. A watched hosting domain never changes the hosting calendar.

## Refused native renewal payments

Native cron's first saved-method charge enrolls an eligible recurring renewal in
bounded retries. A definite zero-money refusal permits at most three new attempts,
each 48 hours after the preceding refusal, before the original renewal due date
(start of that date in the billing calendar). This spacing lets temporary card
problems clear within a seven-day renewal window; it is fixed, with no staff setting.
Only temporary card refusals are retried; hard declines, expired cards and explicit
no-retry advice stop collection. The customer gets a refusal notice with an invoice
payment link and retry guidance.
No old failed invoice is enrolled retrospectively. A newly issued renewal already due
today or earlier gets one debit at that billing run and no retries. All other authorization
checks still apply; a missing due date fails closed. Renewals first charged before their
due date retain their original cutoff, including after an interrupted attempt.

Before reserving and confirming each retry, current locked reads check the unpaid
balance, invoice items, service ownership, active recurring service, unchanged
expiry, cancellation and the same active owned AE Stripe agreement selected on
**each service**. Turning the account switch on or off stops a waiting retry at once,
and Off also unlinks every service, so later scans find nothing to retry. Turning it on
never authorizes an open invoice. Changing or removing the card also stops this schedule;
pay the open invoice.
A customer-initiated Pay takes over without the automatic retry policy.

The schedule and count live in the existing durable checkout attempt. A due retry
first proves the refused intent cancelled, then atomically reserves the next shared
attempt with the incremented count. Interrupted creation/confirmation resumes the
same debit; it never consumes another retry or creates a second in-flight payment.
An expired, proven-unconfirmed creation rotates atomically with its original authority
and retry count. Automatic native collection without renewal context fails closed.
A definite refusal and its waiting/stopped transition commit before confirmation locks
are released; another automatic caller resumes the claimed confirmation identity.
Retiring a refused intent before applying partial credit preserves the waiting schedule
and count while a positive balance remains; the due retry rechecks consent and balance.
Full payment, cancellation and explicit customer Pay end automatic collection. Native
cron consumes only a renewal refusal whose linked notice has already been delivered;
other failures keep the native notification path.
Processing, authentication and uncertain outcomes use existing recovery and review,
never the refusal timer. Paid/cancelled invoices, opt-out and the original cutoff
stop new automatic debits; recovery can still account for money already sent.
Keep Paymenter's scheduler running: native `app:cron-job` creates/charges renewals;
`ae-stripe:charge-attempts` is the scheduled five-minute callback that recovers
outstanding payments and starts eligible bounded retries. It is not a standalone
Artisan command. Neither path is proved live by offline tests.

Domains' retained renewal invoices have null native service references.
`DomainBilling`/`RenewalAutoPay` owns the operation, current renewal switches and
confirmed expiry, so these invoices are deliberately excluded. The smallest needed
interface is a Domains-owned, current-read authorization for a retained invoice:
`renewalAutoPayContext(invoiceId)` returning service ID, selected agreement ID,
operation/generation identity and a strict collection cutoff, or no authorization.
Domains must revalidate it immediately before a new confirmation, using its own
lock order, and preserve invoice-retirement coordination while a debit is pending.
The cutoff must come from the retained operation's safe renewal calendar, never
from the invoice issue date (Domains issues renewals ahead of expiry). A future
adapter can attach that context to the same shared attempt and retry policy; Stripe
must not inspect or update Domains tables. No Domains files change here.

## Stripe Link

Both Payment Elements leave Link on (no `wallets` option), so customers can pay
with or save a Link wallet. Stripe's own Link sign-up box ("Save my information for
faster checkout") keeps its default: ticked. Stripe documents that default-on sign-up
in [Link in the Payment Element](https://docs.stripe.com/payments/link/payment-element-link#enable-accelerated-sign-up).
That box signs the customer up for a Stripe Link account; it is not this
extension's saving consent. Ticked, it may ask for a mobile number.
Automated card tests that type into real Stripe Elements untick the optional
`input[name="linkOptIn"]` inside the Stripe frame, so they need no phone number.
