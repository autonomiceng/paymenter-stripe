<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Gateways\AeStripe\ConvertFromStripeSavedCommand;

(function () {
    $tables = DB::$tables;
    \Illuminate\Support\Facades\Schema::$foreignTables = ['other_app' => ['stripe_saved_payment_fees' => [['stripe_saved_probe' => 'foreign']]]];
    \Illuminate\Support\Facades\Schema::$inspectedTables = [];
    DB::$tables = [
        'extensions' => [['id' => 42, 'type' => 'gateway', 'extension' => 'StripeSaved', 'name' => 'Stripe Saved Methods', 'enabled' => true, 'deleted_at' => null]],
        'settings' => [['id' => 9, 'settingable_id' => 42, 'settingable_type' => \App\Models\Gateway::class, 'key' => 'stripe_secret_key', 'value' => 'encrypted-placeholder']],
        'properties' => [['id' => 11, 'model_id' => 7, 'model_type' => \App\Models\User::class, 'key' => 'stripe_saved_subscription_91', 'value' => '{"key":"immutable","data":{"metadata":{"stripe_saved_gateway":"1"}}}']],
        'audits' => [['id' => 13, 'event' => 'stripe_saved_payment_review', 'new_values' => '{"payment_intent_id":"pi_kept"}']],
        'migrations' => [['id' => 14, 'migration' => '2026_10_22_000001_create_stripe_saved_payment_fees', 'batch' => 2]],
        'billing_agreements' => [['id' => 81, 'gateway_id' => 42, 'external_reference' => 'pm_kept']],
        'services' => [['id' => 91, 'billing_agreement_id' => 81]],
        'stripe_saved_payment_fees' => [['payment_intent_id' => 'pi_kept', 'state' => 'pending', 'stripe_saved_probe' => 'kept']],
    ];
    try {
        $before = DB::$tables;
        $command = new ConvertFromStripeSavedCommand;
        check($command->handle() === 0, 'Conversion defaults to a successful dry run');
        check(DB::$tables === $before, 'Dry run preserves schema, settings and every row');
        check(str_contains(implode("\n", $command->output), 'stripe_saved_payment_fees -> ae_stripe_payment_fees'), 'Dry run describes the table rename');
        $command = new ConvertFromStripeSavedCommand;
        $command->options = ['apply' => true];
        check($command->handle() === 0, 'Explicit apply converts the install');
        check(DB::$tables['extensions'][0] === ['id' => 42, 'type' => 'gateway', 'extension' => 'AeStripe', 'name' => 'AE Stripe', 'enabled' => true, 'deleted_at' => null], 'Conversion preserves the gateway ID and state');
        check(DB::$tables['settings'] === $before['settings'], 'Settings IDs, owner and encrypted values survive conversion');
        check(DB::$tables['billing_agreements'] === $before['billing_agreements'] && DB::$tables['services'] === $before['services'], 'Agreement IDs, gateway references and service references survive conversion');
        check(!isset(DB::$tables['stripe_saved_payment_fees']) && DB::$tables['ae_stripe_payment_fees'] === [['payment_intent_id' => 'pi_kept', 'state' => 'pending', 'ae_stripe_probe' => 'kept']], 'Fee rows move in place without changing payment IDs or state');
        check(str_contains(implode("\n", $command->output), 'Converted gateway #42'), 'Apply reports the converted gateway');
        check(DB::$tables['properties'][0] === array_replace($before['properties'][0], ['key' => 'ae_stripe_subscription_91']), 'Attempt keys move but immutable Stripe retry payloads stay unchanged');
        check(DB::$tables['audits'][0]['event'] === 'ae_stripe_payment_review' && DB::$tables['migrations'][0]['migration'] === '2026_10_22_000001_create_ae_stripe_payment_fees', 'Review guards and migration history keep their identities');
        $converted = DB::$tables;
        foreach ([[], ['apply' => true]] as $options) {
            $command = new ConvertFromStripeSavedCommand; $command->options = $options;
            check($command->handle() === 0 && DB::$tables === $converted, 'Repeated conversion is a no-op in dry run and apply');
            check(str_contains(implode("\n", $command->output), 'Already converted'), 'Idempotent conversion explains the no-op');
        }
        foreach ([null, '2026-10-23'] as $deletedAt) {
            DB::$tables = $before;
            DB::$tables['extensions'][0]['deleted_at'] = $deletedAt;
            DB::$tables['extensions'][] = ['id' => 43, 'extension' => 'AeStripe', 'type' => 'gateway', 'deleted_at' => $deletedAt];
            $conflict = DB::$tables;
            foreach ([[], ['apply' => true]] as $options) {
                $command = new ConvertFromStripeSavedCommand; $command->options = $options;
                check($command->handle() === 1 && DB::$tables === $conflict, 'Both identities refuse without mutations, including soft-deleted rows');
                check(str_contains(implode("\n", $command->output), 'Both StripeSaved and AeStripe'), 'Refusal explains both installed names');
            }
        }
        DB::$tables = $before;
        DB::$tables['ae_stripe_payment_fees'] = [];
        $conflict = DB::$tables;
        $command = new ConvertFromStripeSavedCommand; $command->options = ['apply' => true];
        check($command->handle() === 1 && DB::$tables === $conflict, 'A pre-created target table refuses before any schema or row changes');
        DB::$tables = $before;
        AdoptionQuery::$failTable = 'extensions';
        $command = new ConvertFromStripeSavedCommand; $command->options = ['apply' => true];
        try {
            check($command->handle() === 1, 'A row-update failure reports failure after DDL');
            check(isset(DB::$tables['ae_stripe_payment_fees']) && !isset(DB::$tables['stripe_saved_payment_fees']), 'Completed DDL remains outside the rolled-back row transaction');
            check(DB::$tables['extensions'] === $before['extensions'] && DB::$tables['properties'] === $before['properties'], 'A failed row phase rolls back identity and property updates together');
        } finally { AdoptionQuery::$failTable = null; }
        $command = new ConvertFromStripeSavedCommand; $command->options = ['apply' => true];
        check($command->handle() === 0 && DB::$tables === $converted, 'A failed row phase can resume over completed DDL');
        DB::$tables = $before;
        // Simulate an interruption after DDL but before the gateway row transaction.
        DB::$tables['ae_stripe_payment_fees'] = DB::$tables['stripe_saved_payment_fees'];
        unset(DB::$tables['stripe_saved_payment_fees']);
        $command = new ConvertFromStripeSavedCommand; $command->options = ['apply' => true];
        check($command->handle() === 0 && DB::$tables === $converted, 'Interrupted DDL resumes without duplicating or losing rows');
    } finally {
        $foreignReads = array_filter(\Illuminate\Support\Facades\Schema::$inspectedTables, fn ($name) => str_starts_with($name, 'other_app.'));
        \Illuminate\Support\Facades\Schema::$foreignTables = [];
        DB::$tables = $tables;
        check($foreignReads === [], 'Conversion never inspects or edits another accessible database');
    }
})();
