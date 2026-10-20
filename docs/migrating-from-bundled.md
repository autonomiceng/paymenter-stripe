# Migrate from bundled Stripe

No converter is provided. Plan and review the database changes in an isolated
copy first. Use the same Stripe account and test/live mode: `stripe_id` remains
the shared user property, and upstream Stripe metadata keys keep their meaning.

**Never delete or uninstall the bundled Stripe extension row.** In Paymenter
1.5.9 migration `2025_09_27_182553_create_billing_agreements_table.php`,
`billing_agreements.gateway_id` references `extensions` with `cascadeOnDelete`.
Deleting that row deletes every saved-card agreement attached to it. Keep the
row even after disabling the gateway. Do not remove the bundled source files.

1. Back up the database; record both gateway IDs, agreement counts and service
   references. Pause checkout and automated collection during the cutover.
2. Install/configure the fork with the same Stripe account. Verify its endpoint
   and signing secret in a sandbox, then enable the fork for the cutover.
3. In a reviewed database transaction, repoint existing agreements' `gateway_id`
   from the bundled row ID to the fork ID, preserving agreement IDs, method
   references, service references and properties. Include soft-deleted rows in
   the audit. Reconcile any already-created fork agreement with the same user
   and method ID before updating; do not create duplicate active agreements.
4. Disable bundled Stripe in Paymenter. Disable its old webhook endpoint **in
   Stripe**, then move event delivery to the fork endpoint. Clear or finish old
   checkout sessions. Never deliver events actively to both endpoints.
5. Verify saved-method setup, opt-in/out payments, agreement counts, service
   references, event retries and unchanged Auto Pay settings; resume collection.

The bundled webhook does not check `enabled`. Paymenter boots even disabled
gateways, so its old route still processes events after disabling the row. Native
payment helpers deduplicate identical PaymentIntent IDs per invoice, but two
handlers can overwrite transaction ownership or repeat subscription effects.
The fork's enabled check cannot protect the old handler; stopping old delivery
is essential. Late failure/processing events sent to the old handler can still
regress a successful transaction.

When a bundled Stripe row exists, the fork leaves legacy subscription lifecycle
listeners to the bundled gateway, avoiding duplicate update/cancel calls. Without
that row the fork registers its listeners, guarded by its enabled state. Existing
Stripe subscriptions need a dedicated sandbox cutover test, including billing
and cancellation, before changing delivery. This release adds no subscription
converter. Rollback must restore agreement ownership and endpoint delivery while
checkout/collection are paused, preserving both extension rows.
