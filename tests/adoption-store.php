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
        public function __construct(public int $id, private array $values) {}
        public function settings() { return new AdoptionCollection($this->values); }
    }];
}
Http::$handler = fn ($verb, $path, $data) => $path === '/account' && $verb === 'get' ? ['id' => 'acct_fixture'] : throw new RuntimeException('Unexpected adapter HTTP');
$store = new AdoptionStore;
$engine = new Adoption($store);
$original = DB::$tables;
$plan = $engine->run();
$result = $engine->run($plan['digest']);
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
$command->options = ['confirm-paused' => true];
check($command->handle() === 0, 'CLI dry run succeeds with actual adapter');
check(!str_contains(implode('\n', $command->output), 'private fixture'), 'CLI output contains no stored names');
check(in_array('{"service":21,"from":13,"to":12}', $command->output, true), 'CLI prints each changed service id and before/after reference');
$command->options = ['confirm-paused' => true, 'apply' => true, 'plan' => $plan['digest']];
check($command->handle() === 0, 'CLI applies reviewed plan');
preg_match('/Manifest: ([a-f0-9]{32}\.json)/', end($command->output), $match);
check(isset($match[1]), 'CLI reports protected rollback manifest basename');
Http::$handler = fn ($verb, $path, $data) => $path === '/account' ? ['id' => 'acct_fixture'] : ['id' => basename($path)];
$command->options = ['confirm-paused' => true, 'redisplay' => $match[1], 'confirm-setup-consent' => true];
check($command->handle() === 0, 'CLI independently redisplays adopted active methods');
check(count(array_filter($command->output, fn ($line) => str_starts_with($line, 'Redisplay checklist:'))) > 0, 'Redisplay prints its own paused delivery checklist');
DB::$tables['extensions'][0]['enabled'] = false;
DB::$tables['extensions'][0]['deleted_at'] = '2026-01-01 00:00:00';
$command->options = ['confirm-paused' => true, 'rollback' => $match[1]];
check($command->handle() === 0 && DB::$tables['extensions'][0]['deleted_at'] === null, 'CLI rollback finds and restores soft-deleted bundled gateway');
check(count(array_filter($command->output, fn ($line) => str_starts_with($line, 'Rollback checklist:') && str_contains($line, 'restores bundled gateway'))) > 0, 'Rollback prints native gateway restoration and reverse delivery checklist');
DB::$tables['extensions'][0]['enabled'] = true;
check(DB::$tables === $original, 'CLI apply and rollback preserve all rows and properties');
\App\Models\Gateway::$rows['StripeSaved'] = [];
check($command->handle() === 1 && str_contains(end($command->output), 'installed row'), 'CLI refuses uninstalled fork');
\App\Models\Gateway::$rows = null;
foreach (glob($adoptionStorage . '/app/stripe-saved-adoption/*') as $file) unlink($file);
rmdir($adoptionStorage . '/app/stripe-saved-adoption'); rmdir($adoptionStorage . '/app'); rmdir($adoptionStorage);
