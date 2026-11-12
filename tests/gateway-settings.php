<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
// The native staff gateway editor keeps saved secrets out of the page and keeps them on a blank save.
use App\Admin\Resources\GatewayResource\Pages\EditGateway;
use Livewire\Livewire;

nativeSharedCache();

// The gateway picker lists extensions from Composer's class map. List this copy as an installed one would be.
unlink($root . '/vendor');
mkdir($root . '/vendor/composer', 0700, true);
foreach (['vendor', 'vendor/composer'] as $dir) foreach (array_diff(scandir($release . '/' . $dir), ['.', '..', 'composer', 'autoload_classmap.php']) as $entry) {
    symlink($release . '/' . $dir . '/' . $entry, $root . '/' . $dir . '/' . $entry);
}
$classmap = (require $release . '/vendor/composer/autoload_classmap.php') + ['Paymenter\\Extensions\\Gateways\\AeStripe\\AeStripe' => realpath(__DIR__ . '/../extension/AeStripe/AeStripe.php')];
file_put_contents($root . '/vendor/composer/autoload_classmap.php', '<?php return ' . var_export($classmap, true) . ';');

auth()->login($staff);
Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));

// Before any credentials are seeded, a blank restricted key must fail native validation without HTTP.
check(!$nativeGateway->settings()->where('key', 'stripe_secret_key')->exists(), 'No restricted key is saved for the required-field case');
$calls = count($GLOBALS['stripeCalls']);
$unconfigured = Livewire::test(EditGateway::class, ['record' => $nativeGateway->id]);
$unconfigured->set('data.settings.stripe_publishable_key', 'pk_test_publishable')
    ->set('data.settings.stripe_secret_key', '')->call('save');
check($unconfigured->failedRules() === ['data.settings.stripe_secret_key' => ['Required' => []]]
    && $unconfigured->instance()->getErrorBag()->has('data.settings.stripe_secret_key'),
    'A blank restricted key with no saved key has the required validation error');
check(!$nativeGateway->settings()->where('key', 'stripe_secret_key')->exists(), 'The rejected save does not store an empty restricted key');
check(count($GLOBALS['stripeCalls']) === $calls, 'The missing-key validation makes no Stripe verification request');

$credentials = ['stripe_secret_key' => 'rk_test_savedkey', 'stripe_publishable_key' => 'pk_test_publishable',
    'stripe_webhook_secret' => 'whsec_savedsecret'];
foreach ($credentials as $key => $value) $nativeGateway->settings()->create(['key' => $key, 'value' => $value, 'encrypted' => $key !== 'stripe_publishable_key']);
// Verified state for these credentials, so a save that keeps them makes no Stripe calls.
$nativeGateway->settings()->create(['key' => 'ae_stripe_webhook_continuity', 'value' => json_encode(['account' => 'acct_fixture', 'mode' => 'test',
    'endpoint' => 'we_fixture', 'delivery' => 'endpoint', 'credentials' => $credentials, 'previous' => []], JSON_PRETTY_PRINT), 'encrypted' => true]);
$saved = fn (string $key) => $nativeGateway->settings()->where('key', $key)->first()?->value;

$page = Livewire::test(EditGateway::class, ['record' => $nativeGateway->id]);
$html = $page->html();
check(!array_diff(array_keys($page->get('data.settings')), array_column($gateway->getConfig(), 'name')) && !str_contains($html, 'acct_fixture'),
    'The editor sends only its own fields, not the internal webhook state');
foreach (['stripe_secret_key', 'stripe_webhook_secret'] as $key) {
    $field = $page->instance()->form->getComponentByStatePath('settings.' . $key);
    check($page->get('data.settings.' . $key) === null && !str_contains($html, $credentials[$key]),
        "The editor never sends the saved $key to the page");
    check($field->isPassword() && $field->getPlaceholder() === 'Saved, ends in ' . substr($credentials[$key], -4) . '. Leave blank to keep it.',
        "The $key field is masked and says a saved value stays");
}
check($page->get('data.settings.stripe_publishable_key') === $credentials['stripe_publishable_key'], 'The publishable key still shows');

$calls = count($GLOBALS['stripeCalls']);
$continuity = $saved('ae_stripe_webhook_continuity');
check(is_string($continuity) && $continuity !== '', 'The saved webhook continuity is present before the blank save');
$page->call('save');
check(!$page->instance()->getErrorBag()->any(), 'A save with blank secret fields passes validation: ' . json_encode($page->instance()->getErrorBag()->toArray()));
foreach ($credentials as $key => $value) check($saved($key) === $value, "A blank save keeps the saved $key");
check($saved('ae_stripe_webhook_continuity') === $continuity, 'A blank save keeps webhook continuity byte-for-byte');
check(count($GLOBALS['stripeCalls']) === $calls, 'Keeping the saved credentials needs no Stripe verification');

// A new signing secret still replaces the saved one after the usual verification.
$GLOBALS['stripeHandler'] = fn ($verb, $path) => match ([$verb, $path]) {
    ['get', '/account'] => ['id' => 'acct_fixture'],
    ['get', '/webhook_endpoints'] => ['data' => [['id' => 'we_fixture', 'url' => 'https://paymenter.example.com/extensions/ae-stripe/webhook',
        'enabled_events' => ['*'], 'metadata' => (object) []]], 'has_more' => false],
};
$page->set('data.settings.stripe_webhook_secret', 'whsec_replaced')->call('save');
check($saved('stripe_webhook_secret') === 'whsec_replaced' && $saved('stripe_secret_key') === $credentials['stripe_secret_key'],
    'A typed secret replaces only its own saved value');

$fresh = Livewire::test(EditGateway::class, ['record' => $nativeGateway->id]);
check($fresh->get('data.settings.stripe_webhook_secret') === null && !str_contains($fresh->html(), 'whsec_replaced')
    && !str_contains($fresh->html(), 'whsec_savedsecret')
    && $fresh->instance()->form->getComponentByStatePath('settings.stripe_webhook_secret')->getPlaceholder() === 'Saved, ends in aced. Leave blank to keep it.',
    'The replaced secret is masked when the editor opens again');

// Other gateways can use the same names. Their fields keep native formatting and dehydration.
$bundled = App\Models\Gateway::create(['name' => 'Bundled Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
foreach ($credentials as $key => $value) $bundled->settings()->create(['key' => $key, 'value' => $value, 'encrypted' => $key !== 'stripe_publishable_key']);
$other = Livewire::test(EditGateway::class, ['record' => $bundled->id]);
foreach (['stripe_secret_key', 'stripe_webhook_secret'] as $key) {
    $field = $other->instance()->form->getComponentByStatePath('settings.' . $key);
    check($other->get('data.settings.' . $key) === $credentials[$key], "Another gateway's saved $key still shows");
    foreach ([null, '', '  ', 'other-secret', '0'] as $value) {
        $field->state($value)->callAfterStateHydrated();
        check(data_get($other->instance(), $field->getStatePath()) === $value, "Another gateway's $key keeps its hydrated value");
        check($field->getStateToDehydrate($value) === [$field->getStatePath() => $value === '' ? null : $value],
            "Another gateway's $key keeps native blank and populated submission values");
    }
}
