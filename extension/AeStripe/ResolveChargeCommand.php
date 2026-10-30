<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice};
use Illuminate\Console\Command;

final class ResolveChargeCommand extends Command
{
    protected $signature = 'ae-stripe:resolve-charge {invoice} {--intent= : Verified Stripe payment ID} {--no-charge-proof= : Evidence from Stripe request logs or support; an empty search is not proof} {--accounted-proof= : Proof that final payment funds were refunded or credited}';
    protected $description = 'Resolve an older payment whose identity Stripe could not recover automatically';

    public function handle(): int
    {
        try {
            $row = Gateway::where('extension', 'AeStripe')->firstOrFail();
            $gateway = ExtensionHelper::getExtension('gateway', 'AeStripe', $row->settings);
            $gateway->resolveChargeAttempt(Invoice::findOrFail($this->argument('invoice')), $this->option('intent'), $this->option('no-charge-proof') ?? '', $this->option('accounted-proof') ?? '');
            $this->info('Payment review resolved. Automatic checks will continue.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Payment review was not resolved. Check the payment ID or proof in the original Stripe account.');
            return self::FAILURE;
        }
    }
}
