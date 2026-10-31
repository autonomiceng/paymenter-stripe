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

## Stripe Link save prompt

Both Payment Elements use `wallets: {link: 'never'}`. Stripe's current
[Payment Element option reference](https://docs.stripe.com/js/elements_object/create_payment_element#payment_element_create-options-wallets)
lists `link` and documents `never`. This hides Link in these Elements, including its
separate save prompt, rather than manipulating a cross-origin checkbox. Cards,
other wallets and native saved-agreement records stay intact. This does not change
Stripe Dashboard settings. Stripe also documents the default-on accelerated signup
in [Link in the Payment Element](https://docs.stripe.com/payments/link/payment-element-link#enable-accelerated-sign-up).
Docs checked 2026-10-31; actual Stripe.js behavior still needs the authorized TEST click.
