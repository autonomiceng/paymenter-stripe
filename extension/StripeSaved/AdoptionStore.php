<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Gateways\StripeSaved;

use App\Models\Gateway;
use Illuminate\Support\Facades\{DB, Http};

final class AdoptionStore
{
    private array $gateways;
    private string $directory;
    private $lock;

    public function __construct()
    {
        $this->directory = storage_path('app/stripe-saved-adoption');
        $existing = $this->directory;
        while (!is_dir($existing)) $existing = dirname($existing);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true)) throw new AdoptionRefusal('Storage unavailable');
        if (!chmod($this->directory, 0700)) throw new AdoptionRefusal('Private manifest storage required');
        for ($directory = $this->directory; ; $directory = dirname($directory)) {
            $this->syncDirectory($directory);
            if ($directory === $existing) break;
        }
        $this->lock = fopen($this->directory . '/command.lock', 'c');
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) throw new AdoptionRefusal('Another adoption command is running');
        chmod($this->directory . '/command.lock', 0600);
        $this->configuration();
    }

    public function configuration(): array
    {
        $config = $this->gateways = [];
        foreach (['Stripe', 'StripeSaved'] as $extension) {
            // Rollback must still find the bundled row after native admin Delete (soft delete).
            $rows = Gateway::withTrashed()->where('extension', $extension)->get();
            if ($rows->count() !== 1) throw new AdoptionRefusal('Exactly one installed row per gateway required');
            $gateway = $rows->first();
            $this->gateways[] = $gateway;
            $values = $gateway->settings()->get()->pluck('value', 'key');
            $config[] = ['secret' => (string) ($values['stripe_secret_key'] ?? ''),
                'public' => (string) ($values['stripe_publishable_key'] ?? ''),
                'webhook' => (string) ($values['stripe_webhook_secret'] ?? '')];
        }
        return $config;
    }

    public function transaction(callable $callback): mixed { return DB::transaction($callback); }

    private function rows(string $table, callable $filter): array
    {
        $query = $filter(DB::table($table));
        $rows = [];
        foreach ($query->orderBy('id')->lockForUpdate()->get() as $row) $rows[$row->id] = (array) $row;
        return $rows;
    }

    public function snapshot(bool $allowRetiredBundled = false): array
    {
        $ids = array_map(fn ($g) => $g->id, $this->gateways);
        $extensions = $this->rows('extensions', fn ($q) => $q->whereIn('id', $ids));
        if (count($extensions) !== 2) throw new AdoptionRefusal('Gateway rows changed');
        foreach ($extensions as &$extension) {
            if ($extension['deleted_at'] !== null && (!$allowRetiredBundled || $extension['id'] != $ids[0])) {
                throw new AdoptionRefusal('Gateway row removed');
            }
            $extension = array_intersect_key($extension, array_flip(['id', 'type', 'extension', 'deleted_at']));
        }
        unset($extension);
        $settings = $this->rows('settings', fn ($q) => $q->whereIn('settingable_id', $ids)
            ->whereIn('settingable_type', [Gateway::class, \App\Models\Extension::class]));
        $agreements = $this->rows('billing_agreements', fn ($q) => $q->whereIn('gateway_id', $ids));
        $services = $this->rows('services', fn ($q) => $q->whereIn('billing_agreement_id', array_keys($agreements)));
        $properties = $this->rows('properties', fn ($q) => $q->whereIn('model_id', array_keys($agreements))
            ->where('model_type', \App\Models\BillingAgreement::class));
        return ['bundled' => $ids[0], 'fork' => $ids[1], 'extensions' => $extensions,
            'configuration' => Adoption::digest($this->configuration()), 'settingsHash' => Adoption::digest($settings), 'agreements' => $agreements,
            'services' => $services, 'propertiesHash' => Adoption::digest($properties)];
    }

    public function replace(array $before, array $after): void
    {
        foreach (['extensions' => ['extensions', ['deleted_at']],
            'agreements' => ['billing_agreements', ['gateway_id', 'deleted_at']],
            'services' => ['services', ['billing_agreement_id']]] as $key => [$table, $columns]) {
            foreach ($after[$key] as $id => $row) {
                $changes = [];
                foreach ($columns as $column) if ($before[$key][$id][$column] !== $row[$column]) $changes[$column] = $row[$column];
                // Raw updates intentionally avoid gateway cancellation hooks and timestamp/property changes.
                if ($changes && DB::table($table)->where('id', $id)->update($changes) !== 1) throw new AdoptionRefusal('Row update failed');
            }
        }
    }

    public function stripe(string $key, string $verb, string $path, array $data): array
    {
        return Http::withHeaders(['Authorization' => 'Bearer ' . $key, 'Stripe-Version' => '2025-07-30.basil'])
            ->asForm()->timeout(30)->$verb('https://api.stripe.com/v1' . $path, $data)->throw()->json();
    }

    private function syncDirectory(string $path): void
    {
        $directory = fopen($path, 'r');
        if (!$directory || !fsync($directory)) throw new AdoptionRefusal('Durable manifest storage required');
        fclose($directory);
    }

    private function write(string $path, array $data): void
    {
        $temp = tempnam($this->directory, '.pending-');
        try {
            if ($temp === false || !chmod($temp, 0600)) throw new AdoptionRefusal('Manifest storage unavailable');
            $file = fopen($temp, 'wb');
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (!$file || fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) throw new AdoptionRefusal('Manifest write failed');
            fclose($file);
            if (!rename($temp, $path)) throw new AdoptionRefusal('Manifest rename failed');
            $this->syncDirectory($this->directory);
        } finally {
            if ($temp && is_file($temp)) unlink($temp);
        }
    }

    public function saveManifest(array $plan): string
    {
        $name = bin2hex(random_bytes(16)) . '.json';
        $this->write($this->directory . '/' . $name, $plan);
        return $name;
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.json$/D', $name)) throw new AdoptionRefusal('Use a manifest basename from storage');
        return $this->directory . '/' . $name;
    }

    public function load(string $name): array
    {
        $plan = json_decode(file_get_contents($this->path($name)), true, flags: JSON_THROW_ON_ERROR);
        $digest = $plan['digest'] ?? '';
        unset($plan['digest']);
        if (!hash_equals(Adoption::digest($plan), $digest)) throw new AdoptionRefusal('Invalid manifest digest');
        return $plan + ['digest' => $digest];
    }

    public function outcomes(string $name): array
    {
        $path = $this->path($name) . '.redisplay';
        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    public function saveOutcomes(string $name, array $outcomes): void { $this->write($this->path($name) . '.redisplay', $outcomes); }
    public function throttle(): void { usleep(250000); }
}
