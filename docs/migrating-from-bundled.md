# Migrate from bundled Stripe

Rehearse in an isolated Paymenter/Stripe test-mode copy before live use. Back up
and verify a restore first. Stripe keeps cards on the shared customer (`stripe_id`);
this command changes Paymenter ownership. Auto Pay stays on the same card; services
on active duplicates move to the kept agreement.
Use the same Stripe account and test/live mode. Subscription migration is not included.

**Never hard-delete or uninstall the bundled Stripe extension row.** In Paymenter
1.5.9 migration `2025_09_27_182553_create_billing_agreements_table.php`,
`billing_agreements.gateway_id` references `extensions` with `cascadeOnDelete`.
Hard-deleting that row deletes every saved-card agreement attached to it. Keep both
extension rows and the bundled source files. The admin **Delete** action below is a
soft delete (`deleted_at`), which preserves the row for rollback.

1. Back up the database and record gateway IDs, counts and service references.
   Pause checkout, account payment-method setup, scheduler, queue workers and
   automated collection; drain in-flight work. Stop webhook delivery in Stripe
   Dashboard for both endpoints during the database cutover. Laravel maintenance
   mode alone does not pause workers or incoming webhook work. Paymenter 1.5.9
   exposes no unified collection-pause state; `--confirm-paused` attests this checklist.
2. Install/configure and enable the fork while checkout/collection remain paused.
   The fork stays hidden at checkout while bundled Stripe is active (`enabled=1`
   and not soft-deleted). Changing bundled to `enabled=0` does not hide bundled
   from Paymenter's checkout; use the native retirement action in step 4.
   Verify its endpoint/signing secret in a sandbox before taking the plan snapshot.
   Keep both endpoints disabled during the database and redisplay steps.
   Each gateway needs its configured secret, publishable and webhook signing keys.
   Every invocation requires exactly one `GET /v1/account` with each secret key;
   restricted keys must permit that read. The fork key also needs `POST /v1/payment_methods/{id}`
   write access for optional redisplay and the full [Stripe API permissions](settings.md#stripe-api-permissions)
   for normal operation. There is no bypass. Account IDs and key
   test/live modes must match. The command never changes webhook configuration.
3. From the Paymenter root, as its application user, review a dry run:

   ```sh
   php artisan stripe-saved:adopt-bundled --confirm-paused
   php artisan stripe-saved:adopt-bundled --confirm-paused --apply --plan='<digest>'
   ```

   The first command prints counts, agreement IDs, changed service IDs, canonical duplicate IDs,
   inactive states and a SHA-256 plan digest. It performs no database writes or Stripe
   writes. Copy that digest into the second command. Any drift in the reviewed scope
   refuses apply: rerun the dry run and review again. All agreements on both gateways,
   including soft-deleted rows, referenced services, agreement properties and gateway
   settings are locked and checked in one transaction. Apply refuses if either
   gateway is already soft-deleted. Gateway enable/name changes
   are outside the ownership plan; gateway identity/removal and settings are checked.

   All bundled agreements move to the fork. Duplicate user/method pairs retain the
   oldest active ID referenced by services, otherwise the oldest active ID (or oldest
   referenced/remaining ID if all are deleted). Other active duplicates become
   soft-deleted with a deterministic `2000-01-01 00:00:00` audit marker; their
   rows, external references and properties remain. Active service
   references consolidate on the canonical ID. References to previously deleted
   agreements stay inactive on their original IDs, preserving removed Auto Pay.
   Cross-user duplicates and non-`pm_` references require manual review and refuse.
   The upstream schema makes external references unique; reconciliation also handles
   imported/customized databases that permit duplicates. No extension/agreement is deleted.

   Apply writes a private JSON rollback manifest before any database update to
   `storage/app/stripe-saved-adoption/<manifest>.json`, synchronizing file and directory
   entries before commit. Keep this directory on durable local storage, backed up and
   accessible only to the application user. It contains row snapshots; do not publish
   it. A per-directory lock excludes simultaneous command runs. If interrupted, keep
   collection paused and inspect the manifest against the database; do not blindly
   reapply. A manifest from a failed transaction cannot pass the after-state check.
   Optionally enable one-time redisplay while still paused, before endpoint delivery or other fork activity:

   ```sh
   php artisan stripe-saved:adopt-bundled --confirm-paused --redisplay='<manifest>.json' --confirm-setup-consent
   ```

   Paymenter v1.5.9 `extensions/Gateways/Stripe/Stripe.php::setupBillingAgreement()`
   creates these agreements from explicit customer SetupIntent setup,
   which is the consent basis for redisplay. Attest that the imported active agreements
   came from that flow and your customer-facing terms cover later presentation; do not
   attest for unknown imports or inferred consent. This step sets `allow_redisplay=always`
   only for originally active adopted methods, including consolidated duplicates.
   Deleted methods are excluded. It makes no Stripe reads beyond the two account reads.
   Attempts are spaced at least 250 ms apart; a private `.redisplay` outcome file records
   `ok` or `retry` per method. Rerun the same command to retry failures, with successful
   methods skipped. The setter is idempotent if interrupted before recording success.
   A failure returns a nonzero status without undoing ownership: off-session charging
   still works. Redisplay requires the exact manifest after-state; rollback permits
   only the bundled retirement exception described below. Changing
   gateway settings, creating an agreement, or renewing a referenced service blocks
   both commands for that manifest; neither offers a continuation override.
4. Make the rollback decision before allowing new activity. After adoption apply
   and optional redisplay, use **Delete** on the bundled **Stripe** gateway in
   Paymenter's admin gateway list. This soft-deletes its row: bundled disappears
   from checkout and its webhook route stops booting. Do not hard-delete the row
   or uninstall its source files. Ensure the fork gateway is **enabled before enabling its endpoint**.
   A disabled fork answers signed events with 503; Stripe retries them. In **Stripe
   Dashboard**, keep the old endpoint disabled and enable only the fork endpoint.
   Do not edit signing keys/settings after the plan snapshot. Finish or expire old
   checkout sessions. Never actively deliver to both endpoints; preserve both rows.
   Record the delivery-stop interval, list all Stripe events created during it and
   reconcile/resend required events to the enabled fork endpoint. Do not assume
   disabled endpoints receive a backlog. Check each invoice/transaction before resend.
5. Verify saved setup, opt-in/out payments, counts, service references, event retries,
   missed-event reconciliation, saved-card redisplay and Auto Pay, then resume
   collection. These checks can change scoped rows and close exact manifest rollback.

For rollback, pause/drain the same writers and disable fork webhook delivery first.
Finish or expire fork checkout sessions; keep both endpoints stopped during restore:

```sh
php artisan stripe-saved:adopt-bundled --confirm-paused --rollback='<manifest>.json'
```

This restores the bundled gateway row (`deleted_at` back to null), original gateway ownership, duplicate deleted states and service
references in one locked transaction, only if all scoped rows/settings still match
its recorded after-state, except for the bundled row's retirement `deleted_at`.
Any other drift (including renewal updates to a referenced service)
refuses rollback. There is no override or reviewed-continuation mode: use a
separately reviewed recovery/backup procedure instead, accounting for settled payments. It never reverses
Stripe redisplay, charges, invoices or webhook changes. Verify the restored bundled gateway is active; the fork hides at checkout while bundled is active. Enable only the bundled
webhook endpoint after restore, then reconcile/resend missed events manually before resuming. Preserve both extension rows.

The bundled webhook does not check `enabled`. Paymenter boots even disabled gateways,
so setting `enabled=0` leaves the old route processing events. Admin **Delete** stops
that route from booting on subsequent requests. Native payment helpers deduplicate identical
PaymentIntent IDs per invoice, but two handlers can overwrite transaction ownership or
repeat subscription effects. Late failure/processing events at the old handler can
regress a successful transaction. Stopping old delivery is essential.

When a non-deleted bundled row exists (even `enabled=0`), the fork leaves legacy subscription listeners to it, avoiding
duplicate update/cancel calls. With that row soft-deleted or absent the fork registers listeners;
price/expiry sync requires the fork to be enabled, while existing subscriptions can still be cancelled when disabled.
Cancel or migrate fork subscriptions before deleting the fork gateway, because a deleted gateway is not booted.
Existing Stripe subscriptions need dedicated sandbox billing and
cancellation tests before delivery changes. No subscription converter is provided.

The extension uses its `boot()` hook and Laravel Console Application's starting
callback to register `stripe-saved:adopt-bundled`, following the local Paymenter
1.5.9 source lifecycle. No tinker or core application changes are required. Native
command discovery is unverified; test it in the installed sandbox before release.

The fork marks its PaymentIntents, SetupIntents and subscription schedules/phases
with `stripe_saved_gateway=1`. While a non-deleted bundled Stripe row exists (even
`enabled=0`), the fork acknowledges unmarked intent and subscription events without
effects and logs only the event type and local invoice ID. After bundled Stripe is
soft-deleted or absent, the fork accepts
unmarked events so in-flight bundled payments can settle after cutover. Fee updates
require a transaction already owned by the fork; they never change bundled transactions.
Keep the old endpoint stopped: these guards cannot stop the bundled handler itself.
