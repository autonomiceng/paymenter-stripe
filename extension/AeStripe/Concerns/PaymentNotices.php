<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{Audit, Gateway, Invoice, Notification, User};
use Illuminate\Support\Facades\DB;

trait PaymentNotices
{
    // Call under the event's existing owner/gateway/fee lock, in the transaction recording it.
    private function queuePaymentNotices($audit, array $notices): void
    {
        if (!isset($audit->new_values['notices'])) $audit->update(['new_values' =>
            array_replace($audit->new_values, ['notices' => $notices, 'notice_sent' => false])]);
        DB::afterCommit(fn () => $this->deliverPaymentNotices($audit->id));
    }

    private function staffPaymentNotices(string $scope, array $notice): array
    {
        $notices = [];
        foreach ($this->noticeStaff($scope) as $staff) $notices[] = ['user_id' => $staff->id] + $notice;
        return $notices;
    }

    private function deliverPaymentNotices(int $id): void
    {
        try {
            DB::transaction(function () use ($id) {
                // Never reacquire the event's owner or money locks. Recipient FKs follow only this marker.
                $audit = Audit::where('id', $id)->lockForUpdate()->firstOrFail();
                $values = $audit->new_values;
                if (($values['notice_sent'] ?? true) || !isset($values['notices'])) return;
                $notices = $values['notices'];
                $live = [];
                foreach (User::whereIn('id', array_column($notices, 'user_id'))->get() as $recipient) $live[$recipient->id] = true;
                usort($notices, fn ($a, $b) => $a['user_id'] <=> $b['user_id']);
                foreach ($notices as $notice) {
                    if (isset($live[$notice['user_id']])) Notification::create($notice);
                }
                $audit->update(['new_values' => array_replace($values, ['notice_sent' => true])]);
            });
        } catch (\Throwable $e) { report($e); } // Roll back inserts and retain the marker for scheduled retry.
    }

    private function retryPaymentNotices(): void
    {
        $events = ['ae_stripe_attempt_review', 'ae_stripe_payment_refused', 'ae_stripe_access_refused', 'ae_stripe_payment_review',
            'ae_stripe_fee_review', 'ae_stripe_receipt_return', 'ae_stripe_receipt_reversal'];
        // The native auditable index narrows the rows before checking our events and JSON marker.
        foreach (Audit::whereIn('auditable_type', [(new Invoice)->getMorphClass(), (new Gateway)->getMorphClass()])
            ->whereIn('event', $events)->where('new_values->notice_sent', false)->orderBy('id')->limit(100)->get() as $audit) {
            if (isset($audit->new_values['notices'])) $this->deliverPaymentNotices($audit->id);
        }
    }
}
