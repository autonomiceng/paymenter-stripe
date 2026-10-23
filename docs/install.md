# Install

Requires Paymenter 1.5.9 and PHP 8.3+. Uses Paymenter's HTTP client; no Composer
or production dependency installation. Test-mode sandbox validation is required
before accepting live payments; see [verification](verification.md).

1. Build: `python3 tools/build_release.py --output dist/AeStripe.zip`.
2. Verify: `(cd dist && sha256sum -c AeStripe.zip.sha256)`.
3. Upload through Paymenter's native extension uploader. The archive has one
   `AeStripe/` directory, installed at `extensions/Gateways/AeStripe/`.
4. Create the **AE Stripe** gateway, configure keys, then enable it.
   Enabling creates a Stripe webhook unless its signing secret is already set.
5. Verify `/extensions/ae-stripe/webhook` is reachable over HTTPS. Subscribe
   to the events automatically registered by the gateway; do not reuse the
   bundled endpoint's signing secret for a newly created Stripe endpoint.
6. Follow [migration](migrating-from-bundled.md) before switching existing users.
   The fork stays hidden at checkout while bundled Stripe is active. After adoption
   apply and optional redisplay, use **Delete** on bundled **Stripe** in Paymenter's
   admin gateway list (soft delete). Bundled disappears from checkout and its
   webhook route stops booting; adoption rollback restores the bundled row.

Both extensions can be installed together: class, routes, views and payment
records use distinct gateway identities. Create exactly one gateway row per extension;
Paymenter's configuration lookup selects the first matching row.

Existing StripeSaved development or staging installs must use the explicit
[conversion command](convert-stripe-saved.md) before native installation.
