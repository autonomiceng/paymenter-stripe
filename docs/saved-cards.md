# Duplicate saved cards

Account setup compares Stripe card fingerprints within one Paymenter user, Stripe
customer and AE Stripe gateway. It keeps the oldest matching active agreement and
updates its Stripe and local expiry from the confirmed card. Missing fingerprints
do not match. Service bindings and Auto Pay are unchanged. A duplicate already bound to a service stays saved and is
logged. Other method types and their mandates are unchanged.

Explicit setup consent permits `allow_redisplay=always` on the retained card. A
private user property records the duplicate and retained references before detach,
so setup retries can recognize an already detached duplicate. The latest confirmed
save is recorded for the retained card, so an older unfinished save cannot undo it.
Removing the retained agreement still prevents reuse. The setup redirect returns one native notification:
“This card is already saved. We updated it.”

From the Paymenter root, review existing duplicates with
`php artisan ae-stripe:deduplicate-cards`. It only reads Stripe and lists local
agreement IDs, matching both fingerprint and expiry.
`php artisan ae-stripe:deduplicate-cards --apply` detaches fresh duplicates only when they have no service or account Auto Pay binding.
When resuming a verified already-detached duplicate, it moves remaining owned bindings to the same-card survivor before removing the entry.
It preserves the broadest existing redisplay setting and rechecks ownership and bindings before removal.
Cleanup makes provider calls outside database transactions and rechecks exact local state before writes.
Failed agreements are reported while the remaining rows continue. Busy shared locks and changed plans
are identified as retryable; other failures retain the manual-review message.
If any agreements are skipped, the command reports the count and exits nonzero.
Cards being removed or reserved for another cleanup cannot be survivors. Unresolved bound reservations also exit nonzero.
If the survivor was removed but its original card is still attached to the same customer, apply cancels that unused reservation
without detaching the original. Already-detached cards without a verified survivor remain reported for reconciliation.
Stripe supports changing the retained card’s [expiry fields](https://docs.stripe.com/api/payment_methods/update?api-version=2025-07-30.basil&query=card).
Use a sandbox first. No cleanup has been run against a live installation.

Invoice payment labels such as `Visa **** 4242` are stored in the extension-owned
`ae_stripe_transaction_methods` table (see [verification](verification.md#saved-methods)). Native Paymenter 1.5.9
has no transaction attribute for them, so a theme that wants to show the label reads
that table. This change does not modify core or theme files.

## Copies, removal and cleanup

Saving a card reuses the newest local agreement with the same Stripe fingerprint. Link saves match the full
Stripe Link account email, ignoring case; billing email and masked labels never identify an account. Existing
duplicates and attached Stripe methods remain intact for provider references and Auto Pay. Every verified copy
receives the expiry and consent refresh, including verified receipt-only incoming copies that provider
subscriptions may still charge. Card matching uses the provider fingerprint for every brand. Each copy has
its own newer-consent check. A shared native cache lock
serializes saves, removal and duplicate cleanup. Save, removal and cleanup HTTP run outside database owner transactions. Cleanup verifies provider
identity outside locks, then rechecks the exact customer, agreement set, local details, receipt state and
current service/account bindings before reserving its plan and after provider writes. A stale plan is retryable;
its committed duplicate receipt permits recovery after an interrupted detach. Consent source receipts
retain acceptance time and prevent a retried save from replacing a later expiry. The theme groups verified
identities for display and keeps the original selected agreement IDs. Offline fakes do not prove distributed
cache behavior or real database concurrency; check those in the test-mode sandbox before release. All workers must use the same database, Redis, Memcached
or DynamoDB cache store and lock namespace. Array, file, null, failover and other stores are refused for saved-method
changes; configure a central store (including its required lock table for database caching).

Repeated Add card returns wait up to five seconds for the saved receipt, then reuse its result. A longer wait
shows a retry notice. Removal verifies and detaches every matching local method and its receipt-linked incoming
copies outside the owner lock. A confirmed missing candidate is skipped; a confirmed missing clicked method
uses its server-verified identity index to find remaining copies. Without that identity it removes only its own
local entry and warns that other copies could not be verified. Pending and completed save receipts are marked
removed so a delayed setup or payment webhook cannot restore them. Cleanup redirects completed receipts to the
verified surviving agreement, including retries after a detach. Unrelated receipt-only methods with a verified
different identity are skipped before Stripe reads; unknown identities are still verified remotely. Other provider failures stop native local
removal so it can finish on retry. Confirmed detached copies are reconciled immediately even if a later copy fails:
their owned service/account links are cleared, their local entries retired and their receipts prevented from replaying.

Removal records its verified plan and per-copy detach progress in native user properties before final
local cleanup. If the customer or an agreement changes during detach, reconciliation removes only the
original agreements still owned by that user and gateway, unlinks their services, and preserves reassigned
rows. Interrupted removal prevents delayed saves from restoring its references; provider failures keep
local removal pending for retry. No schema or extension version change is required.

Checkout first saves and duplicate saves retain the same success notice shape as Add card. Each checkout tab
sends an opaque token through confirmation, bank authentication and the invoice return URL. The notice is stored
in native user properties under `ae_stripe_notice_<token hash>`, scoped to that user and invoice. The matching
native invoice component claims it under the user row lock; requests with stale sessions cannot deliver it twice.
A consumed marker prevents repeated confirmation from restoring it. Requests without the token, other invoices/users
and non-invoice components leave it untouched. A failed delivery transaction can retry. Notice state is independent
of cleanup snapshots and the payment-attempt recovery namespace.

Account card selection, the Auto Pay switch, new-service inheritance after checkout commits, and the native service/invoice
recurring-default actions use the same shared per-user lock as cleanup. Binding a reserved or removing
reference returns a retry message before any preference or consent changes. Turning Auto Pay off remains
available. Cleanup verifies the survivor again after detachment, then moves remaining owned service and
account links to that same-card survivor under the owner lock, preserving consent and other settings.
Interrupted detached plans resume reconciliation even when bound; unresolved ownership, customer or
identity conflicts remain reported with a nonzero exit on every rerun, including a read-only dry run.

Removal marks a reference in progress before HTTP and permanently removed only after its detach is
confirmed. A fresh Add card save of the same physical card may create a new provider reference while a
failed removal is pending, without reusing or refreshing its uncertain references. Retrying Remove still
verifies all matching copies, including the newly saved one. Confirmed removed references remain removed.

## Checkout save notices

Checkout confirmation records include the payment intent and original tab token before saving starts.
A successful webhook or the scheduled recovery completes a deferred confirmation, and the original
invoice can receive it on a later poll. Refused saving produces no success. Reusing a token for another
intent allocates a distinct stable token. Abandoned pending, ready and consumed notices expire after 30 days; existing saved
method receipts preserve delivery history. Missing intents back off independently, and irrecoverable
confirmations stop scheduled recovery after 20 attempts. A later successful webhook can still finish
a retained confirmation. Access errors, rate limits, connection, unreadable responses and local failures only back off;
they never consume the confirmation attempt budget. A succeeded intent whose notice completion is
still pending also keeps its budget. Only missing, mismatched or non-succeeded intent outcomes
consume attempts. A payment-method 404 during saving is ambiguous and keeps the succeeded intent's
budget; a definite removed-method receipt still refuses replay. A true intent `resource_missing`
waits a day and uses the intent budget. Exhaustion distinguishes a missing intent from an intent
that did not succeed or match. Earlier access warnings include a sample invoice/intent and stage,
aggregated per configured key and reason to at most once an hour, without keys or response bodies.
401/403, rate limits, server and connection outages stop each recovery pass; per-row 400/402/409
and saving-stage 404 back off while allowing unrelated rows to proceed. Non-consuming failures
grow an independent bounded backoff and never create a terminal state.
Notice-only maintenance failures do not fail settlement or unrelated inheritance.
The invoice keeps a notice-specific poll until completion/refusal/expiry, including after payment polling stops. Confirmations for intents older than 30 days are not recreated.
The original tab explains delayed saving once and reduces its notice poll to once a minute.
Failed notices stop polling but can deliver a later webhook success on an explicit refresh.
Recovery and retention skip missing users before limiting a batch and rotate by property ID in
shared cache. Young and future-due records need no owner lock or metadata rewrite. Retention
uses property timestamps for malformed dates; an entirely unknown age starts one safe retention
period. Exact row identity and value are rechecked under the owner lock before any mutation.
Legacy notices keep their original delivery state; new confirmations use a separate intent-bound token.
These changes use existing properties and require no schema or extension version change.
