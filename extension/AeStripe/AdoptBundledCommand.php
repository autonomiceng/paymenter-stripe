<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Gateways\AeStripe;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/Adoption.php';
require_once __DIR__ . '/AdoptionStore.php';

final class AdoptBundledCommand extends Command
{
    protected $signature = 'ae-stripe:adopt-bundled {--apply} {--plan=} {--confirm-paused}
        {--source-gateway=} {--rollback=} {--redisplay=} {--confirm-setup-consent}';
    protected $description = 'Review, adopt or restore bundled Stripe agreements; default is dry run';

    public function handle(): int
    {
        $this->line('Pause checkout, account method setup, queue workers, scheduler and automated collection; drain in-flight work.');
        $rollback = $this->option('rollback');
        $redisplay = $this->option('redisplay');
        if ($rollback) {
            $this->line('Rollback checklist: stop fork delivery in Stripe Dashboard; rollback restores bundled gateway and ownership, then enable only bundled endpoint. The fork hides while bundled is active. Reconcile missed events. Finish fork sessions; retain both rows.');
        } elseif ($redisplay) {
            $this->line('Redisplay checklist: keep both endpoints stopped and writers paused; exact manifest after-state required. No webhook changes are made.');
        } else {
            $this->line('Cutover checklist: stop both endpoints in Stripe Dashboard; finish old sessions; retain both rows. Apply turns off bundled Stripe. After optional redisplay, use admin Delete on bundled gateway (soft delete); enable fork gateway BEFORE only fork delivery; reconcile missed events.');
        }
        try {
            if (!$this->option('confirm-paused')) throw new AdoptionRefusal('Requires --confirm-paused after the checklist');
            $apply = (bool) $this->option('apply');
            if ((int) $apply + (int) (bool) $rollback + (int) (bool) $redisplay > 1
                || ($apply !== (bool) $this->option('plan'))) throw new AdoptionRefusal('Choose dry run, --apply --plan, --rollback or --redisplay');
            if ($redisplay && !$this->option('confirm-setup-consent')) throw new AdoptionRefusal('Redisplay requires --confirm-setup-consent; see migration consent basis');
            $source = $this->option('source-gateway');
            if ($source !== null && filter_var($source, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new AdoptionRefusal('Use --source-gateway with a positive gateway ID');
            }
            $store = new AdoptionStore($source === null ? null : (int) $source);
            $adoption = new Adoption($store);
            if ($rollback) {
                $adoption->rollback($store->load($rollback));
                $this->info('Bundled gateway and ownership restored; redisplay is not reversed. The fork hides while bundled is active. Enable only bundled endpoint in Stripe Dashboard. Reconcile missed events before resuming.');
            } elseif ($redisplay) {
                $outcomes = $adoption->redisplay($store->load($redisplay), $redisplay);
                $this->line(json_encode($outcomes, JSON_THROW_ON_ERROR));
                return in_array('retry', $outcomes, true) ? self::FAILURE : self::SUCCESS;
            } else {
                $plan = $adoption->run($apply ? $this->option('plan') : null);
                $this->line('Agreements: ' . count($plan['before']['agreements']) . '; bundled: ' . count($plan['adopted']) . '; services: ' . count($plan['before']['services']));
                foreach ($plan['actions'] as $action) $this->line(json_encode($action, JSON_THROW_ON_ERROR));
                foreach ($plan['after']['services'] as $id => $service) {
                    $old = $plan['before']['services'][$id]['billing_agreement_id'];
                    if ($old != $service['billing_agreement_id']) $this->line(json_encode(['service' => $id, 'from' => $old, 'to' => $service['billing_agreement_id']]));
                }
                $this->line('Plan: ' . $plan['digest']);
                if ($apply) $this->info('Committed. Core Stripe was turned off. Manifest: ' . $plan['manifest'] . '; redisplay is a separate step.');
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('AE Stripe adoption failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);
            // Do not render HTTP exceptions or row contents: either may contain credentials or PII.
            $safe = $e instanceof AdoptionRefusal;
            $this->error($safe ? $e->getMessage() : 'Operation failed; keep writers paused and inspect protected manifest/database state before retrying');
            return self::FAILURE;
        }
    }
}
