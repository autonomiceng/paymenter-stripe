<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use Paymenter\Extensions\Gateways\StripeSaved\Adoption;

class AdoptionFake
{
    public array $state;
    public array $manifests = [], $results = [], $calls = [];
    public array $accounts = ['acct_fixture', 'acct_fixture'];
    public bool $fail = false, $locked = false, $manifestFail = false, $replaceFail = false;
    public int $sleeps = 0, $writes = 0;
    public array $config;
    public function __construct()
    {
        $this->config = array_fill(0, 2, ['secret' => 'rk_' . 'test_fixture', 'public' => 'pk_' . 'test_fixture', 'webhook' => 'fixture']);
        $this->state = ['bundled' => 1, 'fork' => 2, 'extensions' => [
                1 => ['id' => 1, 'type' => 'gateway', 'extension' => 'Stripe', 'deleted_at' => null],
                2 => ['id' => 2, 'type' => 'gateway', 'extension' => 'StripeSaved', 'deleted_at' => null]],
            'configuration' => Adoption::digest($this->config), 'settingsHash' => 'settings', 'propertiesHash' => 'properties',
            'agreements' => [10 => $this->row(10, 1), 11 => $this->row(11, 1, '2020-01-01 00:00:00'),
                12 => $this->row(12, 1), 13 => $this->row(13, 2), 14 => $this->row(14, 2)],
            'services' => [20 => ['id' => 20, 'billing_agreement_id' => 12, 'other' => 'unchanged'],
                21 => ['id' => 21, 'billing_agreement_id' => 13, 'other' => 'unchanged']]];
        $this->state['agreements'][13]['external_reference'] = 'pm_12';
    }
    private function row($id, $gateway, $deleted = null): array
    {
        return ['id' => $id, 'user_id' => 7, 'gateway_id' => $gateway, 'external_reference' => 'pm_' . $id,
            'deleted_at' => $deleted, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01', 'name' => 'private fixture'];
    }
    public function configuration(): array { return $this->config; }
    public function transaction($callback): mixed
    {
        $original = $this->state;
        $this->locked = true;
        try { return $callback(); } catch (Throwable $e) { $this->state = $original; throw $e; }
        finally { $this->locked = false; }
    }
    public function snapshot(bool $allowRetiredBundled = false): array { check($this->locked, 'Snapshot under transaction and row locks'); return $this->state; }
    public function replace($before, $after): void
    {
        check($this->locked && $before === $this->state, 'Writes under locks against exact state');
        check(count($after['agreements']) === count($before['agreements']), 'No agreement deletes');
        check($this->manifests !== [], 'Durable manifest precedes any database write');
        ++$this->writes;
        $this->state = $after;
        if ($this->replaceFail) throw new RuntimeException('Simulated database failure');
    }
    public function saveManifest($plan): string { if ($this->manifestFail) throw new RuntimeException('Simulated storage failure'); $this->manifests['fixture'] = $plan; return 'fixture'; }
    public function outcomes($name): array { return $this->results[$name] ?? []; }
    public function saveOutcomes($name, $outcomes): void { $this->results[$name] = $outcomes; }
    public function throttle(): void { ++$this->sleeps; }
    public function stripe($key, $verb, $path, $data): array
    {
        $this->calls[] = [$verb, $path, $data];
        if ($path === '/account' && $verb === 'get') {
            $index = count(array_filter($this->calls, fn ($c) => $c[1] === '/account')) - 1;
            return ['id' => $this->accounts[$index % 2]];
        }
        check(!$this->locked, 'Redisplay happens after DB commit');
        check($verb === 'post' && str_starts_with($path, '/payment_methods/') && $data === ['allow_redisplay' => 'always'], 'Only redisplay writes');
        if ($this->fail && $path === '/payment_methods/pm_12') throw new RuntimeException('private transport error');
        return ['id' => basename($path)];
    }
}
$f = new AdoptionFake;
$a = new Adoption($f);
$original = $f->state;
$plan = $a->run();
check($a->run()['digest'] === $plan['digest'], 'Dry run digest stable');
check($f->state === $original && $f->writes === 0 && $f->manifests === [], 'Dry run has no writes');
check(count($f->calls) === 4 && array_unique(array_column($f->calls, 1)) === ['/account'], 'One account read with each configured key per preflight');
$applied = $a->run($plan['digest']);
check($f->state['agreements'][10]['gateway_id'] === 2 && $f->state['agreements'][11]['gateway_id'] === 2, 'All bundled rows including soft deleted move');
check($f->state['agreements'][11]['deleted_at'] === $original['agreements'][11]['deleted_at'], 'Deleted methods never resurrected');
check($f->state['services'][20]['billing_agreement_id'] === 12 && $f->state['services'][21]['billing_agreement_id'] === 12, 'Old referenced duplicate id retained and all service references reconciled');
check($f->state['agreements'][13]['deleted_at'] !== null, 'Duplicate is inactive without deletion');
check(array_keys($f->state['agreements']) === array_keys($original['agreements']), 'Every agreement id preserved');
check($f->manifests['fixture']['after'] === $f->state, 'Recovery manifest contains exact committed state');
$f->fail = true;
$outcomes = $a->redisplay($plan, 'fixture');
check($outcomes === ['pm_10' => 'ok', 'pm_12' => 'retry'], 'Redisplay progress survives partial failure; deleted method excluded');
$f->fail = false;
check($a->redisplay($plan, 'fixture') === ['pm_10' => 'ok', 'pm_12' => 'ok'], 'Redisplay resumes failed method');
$beforeCalls = count($f->calls);
$a->redisplay($plan, 'fixture');
check(count($f->calls) === $beforeCalls + 2 && $f->sleeps === 3, 'Successful methods skipped and attempts rate limited');
$a->rollback($plan);
check($f->state === $original, 'Rollback restores exact ownership, service references and deleted states');
rejects(fn () => $a->rollback($plan), 'Rollback drift; restore from reviewed backup instead');
rejects(fn () => $a->redisplay($plan, 'fixture'), 'Redisplay drift; review ownership first');
$f->state['services'][20]['other'] = 'changed';
rejects(fn () => $a->run($plan['digest']), 'Plan changed; run dry run again');
check($f->writes === 2, 'Stale plan refusal makes no writes');
$f = new AdoptionFake; $a = new Adoption($f); $plan = $a->run(); $a->run($plan['digest']);
$f->state['agreements'][10]['name'] = 'drift';
$drift = $f->state;
rejects(fn () => $a->rollback($plan), 'Rollback drift; restore from reviewed backup instead');
check($f->state === $drift, 'Rollback drift refusal is atomic');
$f = new AdoptionFake; $a = new Adoption($f); $plan = $a->run(); $a->run($plan['digest']);
$f->accounts = ['acct_changed', 'acct_changed'];
rejects(fn () => $a->rollback($plan), 'Rollback drift; restore from reviewed backup instead');
$f = new AdoptionFake; $a = new Adoption($f); $f->accounts[1] = 'acct_other';
rejects(fn () => $a->run(), 'Stripe account mismatch');
$f->accounts[1] = 'acct_fixture'; $f->config[1] = ['secret' => 'rk_' . 'live_fixture', 'public' => 'pk_' . 'live_fixture', 'webhook' => 'fixture'];
rejects(fn () => $a->run(), 'Stripe mode mismatch');
$f->config[1]['secret'] = '';
rejects(fn () => $a->run(), 'Gateway configuration or key mode invalid');
$f = new AdoptionFake; $a = new Adoption($f); $f->state['configuration'] = 'drift';
rejects(fn () => $a->run(), 'Configuration changed during preflight');
$f = new AdoptionFake; $a = new Adoption($f); $f->state['agreements'][13]['user_id'] = 8;
rejects(fn () => $a->run(), 'Method shared across users; manual review required');
$f = new AdoptionFake; $a = new Adoption($f);
// If the fork id is older and referenced, retain it; redisplay still covers the adopted source method.
$f->state['agreements'][10]['external_reference'] = 'pm_12';
$f->state['agreements'][10]['gateway_id'] = 2;
$f->state['services'][22] = ['id' => 22, 'billing_agreement_id' => 10, 'other' => 'unchanged'];
$plan = $a->run(); $a->run($plan['digest']);
check($f->state['services'][20]['billing_agreement_id'] === 10, 'Older fork reference wins');
check($a->redisplay($plan, 'fixture')['pm_12'] === 'ok', 'Merged adopted method still gets redisplay');
$f = new AdoptionFake; $a = new Adoption($f);
$f->state['agreements'][12]['deleted_at'] = '2020-01-01 00:00:00';
$plan = $a->run(); $a->run($plan['digest']);
check($f->state['services'][20]['billing_agreement_id'] === 12 && $f->state['agreements'][12]['deleted_at'] !== null, 'Deleted service reference never becomes effective Auto Pay');
check($f->state['services'][21]['billing_agreement_id'] === 13, 'Active duplicate remains active without resurrecting removed Auto Pay');
$f = new AdoptionFake; $a = new Adoption($f); $plan = $a->run(); $original = $f->state;
$f->manifestFail = true;
rejects(fn () => $a->run($plan['digest']), 'Simulated storage failure');
check($f->state === $original && $f->writes === 0, 'Storage failure refuses before ownership writes');
$f->manifestFail = false; $f->replaceFail = true;
rejects(fn () => $a->run($plan['digest']), 'Simulated database failure');
check($f->state === $original && isset($f->manifests['fixture']), 'Failed database commit retains manifest and rolls back ownership');
rejects(fn () => $a->rollback($f->manifests['fixture']), 'Rollback drift; restore from reviewed backup instead');
foreach (['Adoption.php', 'AdoptionStore.php', 'AdoptBundledCommand.php'] as $file) {
    check(!preg_match('/->(?:delete|forceDelete|truncate)\s*\(|\bDELETE\s+FROM\b/i', file_get_contents(__DIR__ . '/../extension/StripeSaved/' . $file)), 'No destructive operation in ' . $file);
}
check(count(\Illuminate\Console\Application::$callbacks) === 2, 'Command registered during extension boot including bundled-present path');
