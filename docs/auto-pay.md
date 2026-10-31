# Auto Pay integration

Saving a payment method and enabling Auto Pay are separate actions. Auto Pay belongs
to an account default for **new services**, or to an individual service. Paying an
invoice never grants invoice-wide Auto Pay. Customer and staff views use the same words.

## Account default (theme integration)

Resolve AE Stripe through `ExtensionHelper::getExtension('gateway', 'AeStripe')`.
Read `autoPayDefault($user)`: null means Off; otherwise `agreement_id` identifies the
selected native BillingAgreement. Do not choose a first card or derive consent from
boolean properties or old Stripe metadata.

Show an unchecked choice, “Use Auto Pay for new services”, and an owned saved-method
picker. Explain: “Existing services keep their Auto Pay settings.” Consent can say
“Charge renewal invoices when they are issued.” Keep the configured Terms link beside
it. Future services have no exact calendar until provisioned; show their invoice and
charge dates on the service page once scheduled. Never substitute their expiry date.

POST JSON to `route('extensions.gateways.ae-stripe.auto-pay-default')` with the normal
session, Accept application/json and X-CSRF-TOKEN:

```json
{"enabled":true,"agreement_id":123,"consent":true,"version":"auto_pay_new_services_v2"}
```

`agreement_id` is the native numeric ID, not the Stripe reference or ULID. The new
scope requires fresh authenticated acceptance even for an existing saved card. Off:
`{"enabled":false}`. A 200 returns `ok` and `enabled`; preserve the control on errors.
Apply this account choice after a newly saved agreement appears in the native list,
not to an unfinished SetupIntent. No default is inferred from setup completion.

The property `ae_stripe_auto_pay_default` records version, user ID, server acceptance
time and agreement ID. Native Service Created applies it under user/service locks
and current reads, only for recurring services without a selected agreement or
subscription. Service Updated never reapplies it. No existing service backfill,
card changes, new charge or consent migration occurs. A removed agreement makes the
default ineffective; the next opt-in must select an active owned AE Stripe agreement.

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
No old failed invoice is enrolled retrospectively.

Before reserving and confirming each retry, current locked reads check the unpaid
balance, invoice items, service ownership, active recurring service, unchanged
expiry, cancellation and the same active owned AE Stripe agreement selected on
**each service**. The v2 account default applies only when a service is created;
turning it off does not revoke existing service choices and turning it on does not
authorize this invoice. Turn off Auto Pay on the service to stop its future retries.
Changing the selected agreement also stops this schedule; pay the open invoice.
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

## Stripe Link save prompt

Both Payment Elements use `wallets: {link: 'never'}`. Stripe's current
[Payment Element option reference](https://docs.stripe.com/js/elements_object/create_payment_element#payment_element_create-options-wallets)
lists `link` and documents `never`. This hides Link in these Elements, including its
separate save prompt, rather than manipulating a cross-origin checkbox. Cards,
other wallets and native saved-agreement records stay intact. This does not change
Stripe Dashboard settings. Stripe also documents the default-on accelerated signup
in [Link in the Payment Element](https://docs.stripe.com/payments/link/payment-element-link#enable-accelerated-sign-up).
Docs checked 2026-10-31; actual Stripe.js behavior still needs the authorized TEST click.
