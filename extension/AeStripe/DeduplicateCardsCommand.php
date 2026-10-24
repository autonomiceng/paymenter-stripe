<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

use App\Helpers\ExtensionHelper;
use Illuminate\Console\Command;

final class DeduplicateCardsCommand extends Command
{
    protected $signature = 'ae-stripe:deduplicate-cards {--apply}';
    protected $description = 'List duplicate saved cards; --apply removes only duplicates without service bindings';

    public function handle(): int
    {
        try {
            $row = \App\Models\Gateway::where('extension', 'AeStripe')->firstOrFail();
            $gateway = ExtensionHelper::getExtension('gateway', 'AeStripe', $row->settings);
            $skipped = $gateway->deduplicateCards((bool) $this->option('apply'), fn ($line) => $this->line($line));
            if ($skipped > 0) {
                $this->error($skipped . ' agreement(s) skipped. Review them manually.');
                return self::FAILURE;
            }
            $this->info($this->option('apply') ? 'Duplicate cleanup completed.' : 'Dry run. No changes. Use --apply to remove unbound duplicates.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Duplicate cleanup failed. Review the agreements and retry.');
            return self::FAILURE;
        }
    }
}
