<?php
// Paymenter 1.5.9 CronJob.php:102-116, MIT (Paymenter contributors).
// Execute the native after-commit charge/catch without booting unrelated cron jobs.
use App\Helpers\{ExtensionHelper, NotificationHelper};
use Illuminate\Support\Facades\DB;

return new class {
    public int $successFullCharges = 0;
    public function run($invoice, $service): void
    {
        DB::afterCommit(function () use ($invoice, $service) {
            try {
                ExtensionHelper::charge(
                    $service->billingAgreement->gateway,
                    $invoice,
                    $service->billingAgreement
                );

                $this->successFullCharges++;
            } catch (Exception $e) {
                // Ignore errors here
                NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice);
            }
        });
    }
};
