<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice};
use Illuminate\Console\Command;

final class ResolveChargeCommand extends Command
{
    protected $signature = 'ae-stripe:resolve-charge {invoice} {--intent= : Verified Stripe payment ID} {--no-charge-proof= : Evidence from Stripe request logs or support; an empty search is not proof} {--checked-confirm-key= : Confirm key proven not sent by original-account logs covering the attempt dates, or Stripe support} {--accounted-proof= : Proof that final payment funds were refunded or credited}';
    protected $description = 'Resolve a payment Stripe could not recover automatically';

    public function handle(): int
    {
        try {
            $row = Gateway::where('extension', 'AeStripe')->firstOrFail();
            $gateway = ExtensionHelper::getExtension('gateway', 'AeStripe', $row->settings);
            $gateway->resolveChargeAttempt(Invoice::findOrFail($this->argument('invoice')), $this->option('intent'), $this->option('no-charge-proof') ?? '', $this->option('accounted-proof') ?? '', $this->option('checked-confirm-key') ?? '');
            $this->info($this->option('intent') && !$this->option('accounted-proof')
                ? 'Payment linked. Automatic checks will continue.'
                : 'Payment review resolved. Any remaining balance stays unpaid; the customer can pay it.');
            return self::SUCCESS;
        } catch (\App\Exceptions\DisplayException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Payment review was not resolved (' . get_class($e) . '). Check the payment ID or proof in the original Stripe account.');
            return self::FAILURE;
        }
    }
}
