# Verification

Offline, with PHP 8.3+ and Python 3:

```sh
php -n tests/run.php
find extension tests -name '*.php' ! -name '*.blade.php' -exec php -n -l {} \;
python3 tools/build_release.py --output dist/StripeSaved-0.1.0.zip
(cd dist && sha256sum -c StripeSaved-0.1.0.zip.sha256)
```

Tests execute the gateway with PHP-only model, lock and HTTP fakes; unexpected
HTTP requests fail. No Stripe or application server is contacted. They verify
customer binding, consent, intent updates, agreement retry keys/locking, labels,
signatures, disabled handling and monotonic successful payments. Amounts use
integer minor units with half-up rounding and Stripe zero-decimal currencies;
subscription price updates preserve service quantity. Fakes do not
prove real database isolation, native uploader/route behavior or Stripe.js/API
compatibility. Validate these in a separate Paymenter/Stripe test-mode sandbox:

- Install beside bundled Stripe; ensure unique routes/views/config and no
  duplicate subscription listeners even when bundled Stripe is disabled.
- Check CustomerSession feature names on pinned Basil, restricted-key access,
  `customerSessionClientSecret`, saved-card selection and Link email display.
- Verify own-customer isolation, legacy unspecified-redisplay exclusions and
  account setup redisplay. Test card, wallet and Link with saving checked/clear.
- Confirm clearing future usage with `''` and `elements.fetchUpdates()` refresh
  the intent before confirmation, including decline, 3DS and redirect retries.
- Run concurrent success deliveries/setup redirects against the real database;
  assert one agreement/transaction, preserved success after late failed/processing
  events, no automatic service assignment, and unchanged Auto Pay.
- Reject bad/stale signatures; disable the fork and confirm 200/no effects.
  Rehearse migration and legacy subscription billing/cancellation end to end.
