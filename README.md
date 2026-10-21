# Paymenter Stripe Saved Methods

Independent MIT gateway fork of Paymenter 1.5.9. Install beside the bundled
Stripe gateway using the distinct `StripeSaved` class, `gateways.stripe-saved`
views, and `/extensions/stripe-saved/webhook` endpoint.

The `stripe_id` user property stays shared so existing Stripe customers and
attached payment methods remain accessible. Existing Stripe metadata keys
(`invoice_id`, `user_id`, `is_billing_agreement`, `service_id`,
`billing_agreement_id`, `product_id`) retain their upstream meaning.
Use the same Stripe account and mode during migration. Native agreement rows
still belong to a gateway ID; sharing a customer does not migrate those rows.

Upstream provenance and file hashes are in [UPSTREAM.md](UPSTREAM.md).
Paymenter copyright and MIT terms are preserved in [LICENSE](LICENSE).
Modifications copyright (c) 2026 Autonomic Engineering.

Stripe draws saved methods inside its Payment Element. An unchecked opt-in lets
customers save a card during invoice payment; saving never enables Auto Pay.
**Status: pre-release.** Not yet validated against Stripe; the Paymenter/Stripe
sandbox checks are pending. Complete them before accepting live payments.

- [Installation](docs/install.md) and reproducible native ZIP packaging.
- [Settings and consent](docs/settings.md), including legacy redisplay limits.
- [Migration from bundled Stripe](docs/migrating-from-bundled.md): preserve the
  old extension row, adopt agreements with a reviewed dry-run command, and move
  webhook delivery safely.
- [Offline tests and sandbox verification](docs/verification.md).

Run `php -n tests/run.php`; no downloads or network are required. Build with
`python3 tools/build_release.py --output dist/StripeSaved-0.1.0.zip`.

Requires Paymenter 1.5.9 and PHP 8.3+. Build the native ZIP above, then upload it
through Paymenter's extension uploader; follow [installation](docs/install.md)
before configuring or enabling the gateway.

See [CONTRIBUTING.md](CONTRIBUTING.md) for development and [SECURITY.md](SECURITY.md)
for private vulnerability reporting.
