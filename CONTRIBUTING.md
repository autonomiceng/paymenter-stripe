# Contributing

Requires PHP 8.3+; Python 3 is needed only to build release archives. The offline
tests use local fakes and need no dependency installation or network access.

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

Optional command-registration check against an installed Paymenter 1.5.9 vendor tree:
`php -n tests/conversion-bootstrap.php /path/to/paymenter`. It uses local database
fakes and never boots the referenced application or connects to its database.

The modal regression uses Playwright and an installed Paymenter Livewire script:
`PLAYWRIGHT_MODULE=/path/to/playwright LIVEWIRE_SCRIPT=/path/to/paymenter/vendor/livewire/livewire/dist/livewire.js node tests/setup-modal.mjs`.
It renders the setup view inside a teleported Livewire modal. Stripe.js, consent
requests and Livewire responses are local fixtures; no server or Stripe account is needed.
