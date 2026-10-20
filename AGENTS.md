# Development guide

Before checkout, webhook, consent or agreement changes, read
[docs/verification.md](docs/verification.md) for guarantees and sandbox checks.
Before gateway lifecycle changes, read
[docs/migrating-from-bundled.md](docs/migrating-from-bundled.md).
Use [CONTRIBUTING.md](CONTRIBUTING.md) for setup, checks and archive validation.

Keep Paymenter application files, native schema and root Composer dependencies upstream.
Preserve invoice ownership, customer binding, explicit saving consent, idempotency
and settled-payment status. Changes to those guarantees require meaningful regression tests.
Use synthetic fixtures and placeholder credentials; keep operational data private.

Before committing, pass the offline tests and lint every PHP file on PHP 8.3+.
Before release, compare two native ZIP builds and record the sandbox checks actually run.
Keep `dist/` ignored. Use Conventional Commits, preserve contributor attribution,
and use no generated-by trailers.
