<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Gateways\AeStripe;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log, Schema};

final class ConvertFromStripeSavedCommand extends Command
{
    protected $signature = 'ae-stripe:convert-from-stripe-saved {--apply}';
    protected $description = 'Rename an existing StripeSaved install to AE Stripe; default is dry run';

    public function handle(): int
    {
        try {
            $plan = DB::transaction(function () {
                return $this->plan();
            });
            if (is_int($plan)) return $plan;
            ['old' => $old, 'renames' => $renames, 'columns' => $columns, 'updates' => $updates] = $plan;
            foreach ($renames as $from => $to) $this->line($from . ' -> ' . $to);
            foreach ($columns as [$table, $from, $to]) $this->line($table . '.' . $from . ' -> ' . $to);
            foreach ($updates as [$table, $id, $field, $target]) $this->line($table . ' #' . $id . ': rename ' . $field);
            $this->line('Gateway #' . $old[0]->id . ': StripeSaved -> AeStripe');
            $this->line('Recommended: update the existing Stripe webhook URL to ' . url('/extensions/ae-stripe/webhook') . '. Keep its signing secret.');
            if (!$this->option('apply')) {
                $this->info('Dry run. Use --apply with writers and webhook delivery paused.');
                return self::SUCCESS;
            }
            // MySQL DDL commits implicitly. Run it outside row transactions.
            // Completed renames disappear from the next plan if this run is interrupted.
            foreach ($columns as [$table, $from, $to]) Schema::table($table, fn ($blueprint) => $blueprint->renameColumn($from, $to));
            foreach ($renames as $from => $to) Schema::rename($from, $to);
            DB::transaction(function () use ($plan) {
                // Re-plan under fresh row locks after DDL released the preflight locks.
                $fresh = $this->plan();
                if (is_int($fresh) || $fresh['renames'] || $fresh['columns'] || $fresh['old'] != $plan['old'] || $fresh['updates'] !== $plan['updates']) {
                    throw new ConversionRefusal('Rows changed during schema conversion. Keep writers paused and review another dry run.');
                }
                foreach ($fresh['updates'] as [$table, $id, $field, $target]) DB::table($table)->where('id', $id)->update([$field => $target]);
                $gateway = $fresh['old'][0];
                $changes = ['extension' => 'AeStripe'];
                if (in_array($gateway->name ?? null, ['StripeSaved', 'Stripe Saved Methods'], true)) $changes['name'] = 'AE Stripe';
                DB::table('extensions')->where('id', $gateway->id)->update($changes);
            });
            $this->info('Converted gateway #' . $old[0]->id . ' to AE Stripe. IDs, settings and agreement/service references were preserved.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('AE Stripe conversion failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $this->error($e instanceof ConversionRefusal ? $e->getMessage() : 'Conversion failed. Keep writers paused and inspect the schema and rows before retrying.');
            return self::FAILURE;
        }
    }

    private function plan(): array|int
    {
        $old = []; $new = [];
        // Raw rows include soft-deleted gateways and avoid booting either extension.
        foreach (DB::table('extensions')->orderBy('id')->lockForUpdate()->get() as $row) {
            if ($row->extension === 'StripeSaved') $old[] = $row;
            if ($row->extension === 'AeStripe') $new[] = $row;
        }
        if ($old && $new) throw new ConversionRefusal('Both StripeSaved and AeStripe are installed. Remove the second install before conversion.');
        if (count($old) > 1 || count($new) > 1) throw new ConversionRefusal('Exactly one gateway row is required.');
        if (!$old) {
            $this->info($new ? 'Already converted to AE Stripe. No changes.' : 'No StripeSaved install found. No changes.');
            return self::SUCCESS;
        }
        $tables = Schema::getTableListing(DB::connection()->getDatabaseName(), false);
        $renames = [];
        foreach ($tables as $table) {
            if (str_starts_with($table, 'stripe_saved_')) {
                $target = 'ae_stripe_' . substr($table, 13);
                if (Schema::hasTable($target)) throw new ConversionRefusal('Both source and target tables exist: ' . $table);
                $renames[$table] = $target;
            }
        }
        $columns = [];
        foreach ($tables as $table) {
            $names = Schema::getColumnListing($table);
            foreach ($names as $column) {
                if (!str_starts_with($column, 'stripe_saved_')) continue;
                $target = 'ae_stripe_' . substr($column, 13);
                if (in_array($target, $names, true)) throw new ConversionRefusal('Both source and target columns exist: ' . $table . '.' . $column);
                $columns[] = [$table, $column, $target];
            }
        }
        $updates = [];
        foreach (['settings' => 'key', 'properties' => 'key', 'audits' => 'event', 'migrations' => 'migration'] as $table => $field) {
            if (!Schema::hasTable($table)) continue;
            $query = DB::table($table)->where(fn ($query) => $query
                ->where($field, 'like', '%stripe_saved_%')
                ->orWhere($field, 'like', '%stripe-saved%')
                ->orWhere($field, 'like', '%StripeSaved%'));
            if ($table === 'settings') {
                $query->where('settingable_id', $old[0]->id)
                    ->whereIn('settingable_type', [\App\Models\Gateway::class, \App\Models\Extension::class]);
            }
            $rows = $query->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $value = $row->$field;
                $target = str_replace(['stripe_saved_', 'stripe-saved', 'StripeSaved'], ['ae_stripe_', 'ae-stripe', 'AeStripe'], $value);
                if ($target === $value) continue;
                if (in_array($table, ['settings', 'properties'], true)) {
                    $owner = $table === 'settings' ? ['settingable_id', 'settingable_type'] : ['model_id', 'model_type'];
                    $other = DB::table($table)->where($field, $target)
                        ->where($owner[0], $row->{$owner[0]})->where($owner[1], $row->{$owner[1]})
                        ->lockForUpdate()->first();
                    if ($other) throw new ConversionRefusal('Both source and target keys exist in ' . $table . ' for row #' . $row->id);
                }
                $updates[] = [$table, $row->id, $field, $target, (array) $row];
            }
        }
        return compact('old', 'new', 'renames', 'columns', 'updates');
    }
}

final class ConversionRefusal extends \RuntimeException {}
