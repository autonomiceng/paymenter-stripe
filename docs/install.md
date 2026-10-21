# Install

Requires Paymenter 1.5.9 and PHP 8.3+. Uses Paymenter's HTTP client; no Composer
or production dependency installation. Test-mode sandbox validation is required
before accepting live payments; see [verification](verification.md).

1. Build: `python3 tools/build_release.py --output dist/StripeSaved-0.1.0.zip`.
2. Verify: `(cd dist && sha256sum -c StripeSaved-0.1.0.zip.sha256)`.
3. Upload through Paymenter's native extension uploader. The archive has one
   `StripeSaved/` directory, installed at `extensions/Gateways/StripeSaved/`.
4. Create the **Stripe Saved Methods** gateway, configure keys, then enable it.
   Enabling creates a Stripe webhook unless its signing secret is already set.
5. Verify `/extensions/stripe-saved/webhook` is reachable over HTTPS. Subscribe
   to the events automatically registered by the gateway; do not reuse the
   bundled endpoint's signing secret for a newly created Stripe endpoint.
6. Follow [migration](migrating-from-bundled.md) before switching existing users.
   The fork stays hidden at checkout while bundled Stripe is active. After adoption
   apply and optional redisplay, use **Delete** on bundled **Stripe** in Paymenter's
   admin gateway list (soft delete). Bundled disappears from checkout and its
   webhook route stops booting; adoption rollback restores the bundled row.

Both extensions can be installed together: class, routes, views and payment
records use distinct gateway identities. Permit one gateway row per extension;
Paymenter's configuration lookup selects the first matching row.
