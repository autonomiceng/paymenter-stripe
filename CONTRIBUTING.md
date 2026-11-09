# Contributing

Requires PHP 8.3+; Python 3 is needed only to build release archives. The offline
tests use local fakes and need no dependency installation or network access.
Suites listed in `tests/native-suites.php` run on a pristine Paymenter 1.5.9 release
with its Composer dependencies and in-memory SQLite, for example
`PAYMENTER_TEST_APP=/path/to/pristine-release php tests/run.php --receipt-returns`.

```sh
php -n tests/run.php
find extension tests -type f -name '*.php' -exec php -n -l {} \;
```

For checkout, webhook, consent or agreement changes, read [verification](docs/verification.md)
and add regression coverage for failure paths and customer isolation. Read
[migration](docs/migrating-from-bundled.md) before changing gateway lifecycle.
Keep Paymenter application files, native schema and Composer dependencies upstream.
Use synthetic fixtures and placeholder credentials; keep operational data private.

Build native archives with `python3 tools/build_release.py --output dist/first.zip`.
Build again as `dist/second.zip` and compare with `cmp dist/first.zip dist/second.zip`.
Record offline checks separately from the pending Paymenter/Stripe sandbox checks.

Use Conventional Commits and preserve contributor attribution; no generated-by trailers.
Describe changed behavior, validation and remaining limits in each pull request.
Contributions use the project's [MIT license](LICENSE).

CI also runs two matrices against the pinned public Paymenter 1.5.9 (a pristine
release with `vendor` installed and no `.env`). Run them locally the same way:

```sh
PAYMENTER_TEST_APP=/path/to/paymenter PAY_PENDING_MATRIX=1 php tests/run.php
PAYMENTER_TEST_APP=/path/to/paymenter PAY_PENDING_MATRIX=browser PLAYWRIGHT_MODULE=/path/to/playwright php tests/run.php
```

The native matrix boots each case in its own process on in-memory SQLite. It also runs
the standalone native files and the command-registration check, which never boots the
application or connects to its database. The browser matrix runs `tests/payment-errors.mjs`
(both production forms with offline Stripe and network failures), `tests/setup-modal.mjs`
(the setup view inside a teleported Livewire modal) and the native notice poll. Stripe.js,
consent requests and Livewire responses are local fixtures; no server or Stripe account is
needed. `LIVEWIRE_SCRIPT` defaults to the release's Livewire script. A new test file must be
reached from one of these entry points; the offline run fails otherwise.
