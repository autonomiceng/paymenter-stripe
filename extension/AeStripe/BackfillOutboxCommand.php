<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

use Illuminate\Console\Command;

final class BackfillOutboxCommand extends Command
{
    protected $signature = 'ae-stripe:backfill-outbox';
    protected $description = 'Copy existing Stripe settlements and pending notices into extension tables';

    public function handle(): int
    {
        SettlementStore::backfill();
        (new AeStripe)->backfillPaymentNotices();
        $this->info('Stripe settlements and pending notices copied. Repeating this command is safe.');
        return self::SUCCESS;
    }
}
