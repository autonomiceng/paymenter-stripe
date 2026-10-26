# Duplicate saved cards

Account setup compares Stripe card fingerprint, expiry month and expiry year within
one Paymenter user, Stripe customer and AE Stripe gateway. It keeps the oldest
matching active agreement. Missing fingerprints do not match. Service bindings and
Auto Pay are unchanged. A duplicate already bound to a service stays saved and is
logged. Other method types and their mandates are unchanged.

Explicit setup consent permits `allow_redisplay=always` on the retained card. A
private user property records the duplicate and retained references before detach,
so setup retries can recognize an already detached duplicate. Removing the retained
agreement still prevents reuse. The setup redirect returns one native notification:
“This card is already saved.”

From the Paymenter root, review existing duplicates with
`php artisan ae-stripe:deduplicate-cards`. It only reads Stripe and lists local
agreement IDs. `php artisan ae-stripe:deduplicate-cards --apply` detaches and
soft-deletes only duplicates with no service binding. It preserves the broadest
existing redisplay setting and rechecks ownership and bindings before removal.
Failed agreements are reported for manual review while the remaining rows continue.
If any agreements are skipped, the command reports the count and exits nonzero.
Use a sandbox first. No cleanup has been run against a live installation.

Invoice payment labels such as `Visa **** 4242` are stored in the extension-owned
`ae_stripe_transaction_methods` table (see `verification.md`). Native Paymenter 1.5.9
has no transaction attribute for them, so a theme that wants to show the label reads
that table. This change does not modify core or theme files.
