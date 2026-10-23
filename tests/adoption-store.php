<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use Illuminate\Support\Facades\{DB, Http};
use Paymenter\Extensions\Gateways\StripeSaved\{Adoption, AdoptionStore, AdoptBundledCommand};

class AdoptionCollection implements IteratorAggregate
{
    public function __construct(private array $items) {}
    public function get(): self { return $this; }
    public function count(): int { return count($this->items); }
    public function first(): mixed { return reset($this->items) ?: null; }
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }
    public function pluck($value, $key): array { return array_column($this->items, $value, $key); }
}
class AdoptionQuery
{
    public static ?string $failTable = null;
    private array $filters = [];
    private bool $locked = false;
    public function __construct(private string $table) {}
    public function whereIn($key, $values): self { $this->filters[] = fn ($r) => in_array($r[$key], $values); return $this; }
    public function where($key, $value): self { return $this->whereIn($key, [$value]); }
    public function orderBy($key): self { return $this; }
    public function lockForUpdate(): self { check(DB::$locked, 'Real adapter locks in transaction'); $this->locked = true; return $this; }
    private function matches($row): bool { foreach ($this->filters as $f) if (!$f($row)) return false; return true; }
    public function get(): AdoptionCollection
    {
        check($this->locked, 'Adapter snapshot requires row locks for ' . $this->table);
        DB::$locks[] = $this->table;
        return new AdoptionCollection(array_map(fn ($r) => (object) $r, array_filter(DB::$tables[$this->table], fn ($r) => $this->matches($r))));
    }
    public function update($changes): int
    {
        check(DB::$locked, 'Adapter updates in transaction');
        if (self::$failTable === $this->table) throw new RuntimeException('Simulated restore failure');
        $count = 0;
        foreach (DB::$tables[$this->table] as &$row) {
            if (!$this->matches($row)) continue;
            $row = array_replace($row, $changes); ++$count;
        }
        DB::$updates[] = [$this->table, $changes];
        return $count;
    }
}
$adoptionStorage = sys_get_temp_dir() . '/stripe-adoption-test-' . bin2hex(random_bytes(6));
$fixture = new AdoptionFake;
DB::$tables = ['extensions' => [['id' => 1, 'type' => 'gateway', 'extension' => 'Stripe', 'deleted_at' => null, 'enabled' => true], ['id' => 2, 'type' => 'gateway', 'extension' => 'StripeSaved', 'deleted_at' => null, 'enabled' => true]], 'settings' => [],
    'billing_agreements' => $fixture->state['agreements'], 'services' => $fixture->state['services'],
    'properties' => [['id' => 1, 'model_id' => 10, 'model_type' => \App\Models\BillingAgreement::class, 'value' => 'unchanged']]];
\App\Models\Gateway::$rows = [];
foreach (['Stripe', 'StripeSaved'] as $i => $name) {
    $settings = [];
    foreach (['stripe_secret_key' => 'secret', 'stripe_publishable_key' => 'public', 'stripe_webhook_secret' => 'webhook'] as $key => $field) {
        $settings[] = ['key' => $key, 'value' => $fixture->config[$i][$field]];
    }
    \App\Models\Gateway::$rows[$name] = [new class($i + 1, $settings) {
        public bool $rejectUpdates = false;
        public function __construct(public int $id, private array $values) {}
        public function settings() { return new AdoptionCollection($this->values); }
        public function update($changes) {
            if ($this->rejectUpdates) return false;
            check(DB::$locked, 'Native gateway update shares ownership transaction');
            foreach (DB::$tables['extensions'] as &$row) if ($row['id'] === $this->id) $row = array_replace($row, $changes);
        }
        public function __get($key) {
            foreach (DB::$tables['extensions'] as $row) if ($row['id'] === $this->id) return $row[$key] ?? null;
            return null;
        }
    }];
}
// Retired history must not prevent adoption from the current bundled gateway.
$retiredGateway = clone \App\Models\Gateway::$rows['Stripe'][0];
$retiredGateway->id = 28;
DB::$tables['extensions'][] = ['id' => 28, 'type' => 'gateway', 'extension' => 'Stripe', 'deleted_at' => '2020-01-01', 'enabled' => false];
\App\Models\Gateway::$rows['Stripe'][] = $retiredGateway;
Http::$handler = fn ($verb, $path, $data) => $path === '/account' && $verb === 'get' ? ['id' => 'acct_fixture'] : throw new RuntimeException('Unexpected adapter HTTP');
$store = new AdoptionStore;
$engine = new Adoption($store);
$original = DB::$tables;
$plan = $engine->run();
$result = $engine->run($plan['digest']);
check(DB::$tables['extensions'][0]['enabled'] === false, 'Production apply disables bundled Stripe');
check(DB::$tables['billing_agreements'][11]['gateway_id'] === 2, 'Production adapter includes soft-deleted rows');
check(DB::$tables['services'][21]['billing_agreement_id'] === 12, 'Production adapter repoints services');
check(DB::$tables['properties'] === $original['properties'], 'Properties untouched by production adapter');
check(array_unique(DB::$locks) === ['extensions', 'settings', 'billing_agreements', 'services', 'properties'], 'All snapshot tables locked');
$manifest = $store->load($result['manifest']);
check($manifest === $plan, 'JSON manifest round trips with exact types and digest');
check((fileperms($adoptionStorage . '/app/stripe-saved-adoption/' . $result['manifest']) & 0777) === 0600, 'Manifest permissions private');
rejects(fn () => new AdoptionStore, 'Another adoption command is running');
$engine->rollback($manifest);
check(DB::$tables === $original, 'Production updates restore exact raw tables');
$engine->run($plan['digest']);
DB::$tables['extensions'][0]['deleted_at'] = '2026-01-01 00:00:00';
$retired = DB::$tables;
foreach ([['extensions', 0, 'type', 'other'], ['extensions', 0, 'extension', 'Other'],
    ['extensions', 1, 'extension', 'Other'], ['billing_agreements', 10, 'name', 'drift'],
    ['billing_agreements', 10, 'gateway_id', 1], ['billing_agreements', 10, 'deleted_at', '2026-01-01'],
    ['services', 20, 'other', 'drift'], ['properties', 0, 'value', 'drift']] as [$table, $id, $column, $value]) {
    DB::$tables[$table][$id][$column] = $value;
    $drifted = DB::$tables;
    rejects(fn () => $engine->rollback($manifest), 'Rollback drift; restore from reviewed backup instead');
    check(DB::$tables === $drifted, 'Retirement does not permit other drift: ' . $table . '.' . $column);
    DB::$tables = $retired;
}
DB::$tables['settings'][] = ['id' => 1, 'settingable_id' => 1, 'settingable_type' => \App\Models\Gateway::class, 'key' => 'drift', 'value' => 'drift'];
rejects(fn () => $engine->rollback($manifest), 'Rollback drift; restore from reviewed backup instead');
DB::$tables = $retired;
DB::$tables['billing_agreements'][99] = array_replace(DB::$tables['billing_agreements'][10], ['id' => 99]);
rejects(fn () => $engine->rollback($manifest), 'Rollback drift; restore from reviewed backup instead');
DB::$tables = $retired;
DB::$tables['extensions'][1]['deleted_at'] = '2026-01-01 00:00:00';
rejects(fn () => $engine->rollback($manifest), 'Gateway row removed');
DB::$tables = $retired;
AdoptionQuery::$failTable = 'billing_agreements';
rejects(fn () => $engine->rollback($manifest), 'Simulated restore failure');
check(DB::$tables === $retired, 'Failed ownership restore rolls bundled resurrection back in the same transaction');
AdoptionQuery::$failTable = null;
$engine->rollback($manifest);
check(DB::$tables === $original, 'Rollback restores native-retired bundled row and original ownership atomically');
foreach ([0, 1] as $row) {
    DB::$tables['extensions'][$row]['deleted_at'] = '2026-01-01 00:00:00';
    $deleted = DB::$tables;
    $updates = count(DB::$updates);
    rejects(fn () => $engine->run($plan['digest']), 'Gateway row removed');
    check(DB::$tables === $deleted && count(DB::$updates) === $updates, 'Apply refuses soft-deleted gateway without writes: ' . $row);
    DB::$tables = $original;
}
\App\Models\Gateway::$rows['Stripe'][0]->rejectUpdates = true;
rejects(fn () => $engine->run($plan['digest']), 'Gateway update failed');
check(DB::$tables === $original, 'Refused native update rolls ownership back');
\App\Models\Gateway::$rows['Stripe'][0]->rejectUpdates = false;
foreach ([true, false] as $enabledBefore) {
    DB::$tables['extensions'][0]['enabled'] = $enabledBefore;
    $beforeApply = DB::$tables;
    $enabledPlan = $engine->run();
    AdoptionQuery::$failTable = 'billing_agreements';
    rejects(fn () => $engine->run($enabledPlan['digest']), 'Simulated restore failure');
    check(DB::$tables === $beforeApply, 'Failed apply restores enabled state and ownership together');
    AdoptionQuery::$failTable = null;
    $engine->run($enabledPlan['digest']);
    DB::$tables['extensions'][0]['enabled'] = true;
    rejects(fn () => $engine->rollback($enabledPlan), 'Rollback drift; restore from reviewed backup instead');
    rejects(fn () => $engine->redisplay($enabledPlan, $result['manifest']), 'Redisplay drift; review ownership first');
    DB::$tables['extensions'][0]['enabled'] = false;
    $engine->rollback($enabledPlan);
    check(DB::$tables === $beforeApply, 'Rollback restores exact original enabled state');
    DB::$tables = $original;
}
check(!array_filter(DB::$updates, fn ($update) => $update[0] === 'extensions' && array_key_exists('enabled', $update[1])), 'Enabled state never uses raw table updates');
// Old manifests cannot restore an enabled state they never recorded.
$legacy = $manifest;
unset($legacy['before']['extensions'][1]['enabled'], $legacy['after']['extensions'][1]['enabled']);
$engine->run($plan['digest']);
$accountHandler = Http::$handler;
Http::$handler = fn ($verb, $path, $data) => $path === '/account' ? ['id' => 'acct_fixture'] : ['id' => basename($path)];
check($engine->redisplay($legacy, $result['manifest']) === ['pm_10' => 'ok', 'pm_12' => 'ok'], 'Redisplay accepts manifests created before enabled state was recorded');
$engine->rollback($legacy);
check(DB::$tables['extensions'][0]['enabled'] === false, 'Legacy rollback preserves current enabled state');
DB::$tables = $original;
Http::$handler = $accountHandler;
$store->saveOutcomes($result['manifest'], ['pm_10' => 'retry']);
check($store->outcomes($result['manifest']) === ['pm_10' => 'retry'], 'Atomic outcome file round trip');
rejects(fn () => $store->load('../escape'), 'Use a manifest basename from storage');
$bad = $manifest; $bad['before']['bundled'] = 9;
$badName = $store->saveManifest($bad);
rejects(fn () => $store->load($badName), 'Invalid manifest digest');
$command = new AdoptBundledCommand;
check($command->handle() === 1 && str_contains(end($command->output), '--confirm-paused'), 'CLI refuses missing pause attestation');
$command->options = ['confirm-paused' => true, 'apply' => true];
check($command->handle() === 1 && str_contains(end($command->output), 'Choose dry run'), 'CLI rejects apply without plan');
$command->options = ['confirm-paused' => true, 'redisplay' => $result['manifest']];
check($command->handle() === 1 && str_contains(end($command->output), '--confirm-setup-consent'), 'CLI requires explicit setup consent basis');
unset($store, $engine);
DB::$tables['extensions'][2]['deleted_at'] = null;
$command->options = ['confirm-paused' => true];
check($command->handle() === 1 && str_contains(end($command->output), '1, 28') && str_contains(end($command->output), '--source-gateway=ID'), 'CLI lists ambiguous bundled sources');
$command->options = ['confirm-paused' => true, 'source-gateway' => '1'];
check($command->handle() === 0, 'CLI accepts explicit source among ambiguous gateways');
DB::$tables['extensions'][2]['deleted_at'] = '2020-01-01';
$command->options = ['confirm-paused' => true];
check($command->handle() === 0, 'CLI dry run succeeds with actual adapter');
check(!str_contains(implode('\n', $command->output), 'private fixture'), 'CLI output contains no stored names');
check(in_array('{"service":21,"from":13,"to":12}', $command->output, true), 'CLI prints each changed service id and before/after reference');
$command->options = ['confirm-paused' => true, 'apply' => true, 'plan' => $plan['digest']];
check($command->handle() === 0, 'CLI applies reviewed plan');
preg_match('/Manifest: ([a-f0-9]{32}\.json)/', end($command->output), $match);
check(isset($match[1]), 'CLI reports protected rollback manifest basename');
DB::$tables['extensions'][2]['deleted_at'] = null;
Http::$handler = fn ($verb, $path, $data) => $path === '/account' ? ['id' => 'acct_fixture'] : ['id' => basename($path)];
$command->options = ['confirm-paused' => true, 'redisplay' => $match[1], 'confirm-setup-consent' => true];
check($command->handle() === 0, 'CLI independently redisplays adopted active methods');
check(count(array_filter($command->output, fn ($line) => str_starts_with($line, 'Redisplay checklist:'))) > 0, 'Redisplay prints its own paused delivery checklist');
DB::$tables['extensions'][0]['enabled'] = false;
DB::$tables['extensions'][0]['deleted_at'] = '2026-01-01 00:00:00';
$command->options = ['confirm-paused' => true, 'rollback' => $match[1]];
check($command->handle() === 0 && DB::$tables['extensions'][0]['deleted_at'] === null, 'CLI rollback finds and restores soft-deleted bundled gateway');
check(count(array_filter($command->output, fn ($line) => str_starts_with($line, 'Rollback checklist:') && str_contains($line, 'restores bundled gateway'))) > 0, 'Rollback prints native gateway restoration and reverse delivery checklist');
DB::$tables['extensions'][2]['deleted_at'] = '2020-01-01';
check(DB::$tables === $original, 'CLI apply and rollback preserve all rows and properties');
// A retired source remains selectable when it still has live ownership references.
DB::$tables['billing_agreements'][90] = array_replace($fixture->state['agreements'][10], ['id' => 90, 'gateway_id' => 28]);
rejects(fn () => (new AdoptionStore)->configuration(), 'Multiple bundled Stripe gateways: 1, 28. Use --source-gateway=ID');
DB::$tables['billing_agreements'][90]['deleted_at'] = '2020-01-01';
check(count((new AdoptionStore)->configuration()) === 2, 'Retired source with only removed agreements is ignored');
DB::$tables['services'][90] = ['id' => 90, 'billing_agreement_id' => 90];
rejects(fn () => (new AdoptionStore)->configuration(), 'Multiple bundled Stripe gateways: 1, 28. Use --source-gateway=ID');
DB::$tables = $original;
DB::$tables['services'][90] = ['id' => 90, 'billing_agreement_id' => null, 'subscription_id' => 'sub_legacy'];
DB::$tables['properties'][] = ['id' => 90, 'model_id' => 90, 'model_type' => \App\Models\Service::class, 'key' => 'has_stripe_subscription', 'value' => '1'];
rejects(fn () => (new AdoptionStore)->configuration(), 'Multiple bundled Stripe gateways: 1, 28. Use --source-gateway=ID');
DB::$tables = $original;
\App\Models\Gateway::$rows['Stripe'][0]->id = 62;
DB::$tables['extensions'][0]['id'] = 62;
foreach (DB::$tables['billing_agreements'] as &$row) if ($row['gateway_id'] === 1) $row['gateway_id'] = 62;
unset($row);
$selected = new AdoptionStore;
check((new Adoption($selected))->run()['before']['bundled'] === 62, 'Active source 62 is selected beside retired empty 28');
unset($selected);
\App\Models\Gateway::$rows['Stripe'][0]->id = 1;
DB::$tables = $original;
\App\Models\Gateway::$rows['StripeSaved'] = [];
check($command->handle() === 1 && str_contains(end($command->output), 'installed row'), 'CLI refuses uninstalled fork');
\App\Models\Gateway::$rows = null;
foreach (glob($adoptionStorage . '/app/stripe-saved-adoption/*') as $file) unlink($file);
rmdir($adoptionStorage . '/app/stripe-saved-adoption'); rmdir($adoptionStorage . '/app'); rmdir($adoptionStorage);
