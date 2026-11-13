<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Mail\SystemMail;
use App\Models\{Audit, EmailLog, Gateway, Invoice, Notification, Property, User};
use Illuminate\Support\Facades\{DB, Log, Mail, Queue};
use Paymenter\Extensions\Gateways\AeStripe\DeliverReturnMail;

require_once __DIR__ . '/../DeliverReturnMail.php';

trait PaymentNotices
{
    private function noticeIdentity($audit, bool $staff = false): array
    {
        $v = $audit->new_values;
        if (isset($v['notice_identity'])) return $v['notice_identity'];
        $kind = $audit->event;
        $source = match ($kind) {
            'ae_stripe_attempt_review' => $v['attempt_key'] ?? null,
            'ae_stripe_payment_refused' => $v['notice_source'] ?? $v['notice_key'] ?? null,
            'ae_stripe_access_refused' => $v['key_fingerprint'] ?? null,
            'ae_stripe_payment_review', 'ae_stripe_fee_review' => $v['payment_intent_id'] ?? null,
            'ae_stripe_receipt_return' => $v['refund_id'] ?? $v['key'] ?? null,
            'ae_stripe_receipt_reversal' => $v['stripe_id'] ?? null,
            default => null,
        };
        if (in_array($kind, ['ae_stripe_receipt_return', 'ae_stripe_receipt_reversal'], true)) $kind .= ':' . ($v['state'] ?? $v['status'] ?? 'review');
        if ($staff && $audit->event === 'ae_stripe_receipt_return') $kind .= ':detail:' . hash('sha256', $v['reason'] ?? '');
        return ['kind' => $kind, 'source' => (string) ($source ?: 'audit_' . $audit->id)];
    }

    // Runs in the transaction recording the event. Delivery acquires only its own outbox row.
    private function queuePaymentNotices($audit, array $notices, ?array $identity = null): void
    {
        $identities = [];
        foreach ($notices as $notice) {
            if (!isset($notice['staff_scope'])) $notice['invoice_id'] = $audit->auditable_type === (new Invoice)->getMorphClass() ? (int) $audit->auditable_id : null;
            $key = $identity ?? $this->noticeIdentity($audit, isset($notice['staff_scope']));
            $this->storePaymentNotices($key, [$notice]);
            $identities[$key['kind'] . ':' . $key['source']] = $key;
        }
        DB::afterCommit(function () use ($identities) {
            foreach ($identities as $identity) foreach (DB::table('ae_stripe_outbox')->where('source', $identity['source'])
                ->whereIn('kind', [$identity['kind'] . ':staff', $identity['kind'] . ':customer'])->get() as $row) {
                $this->deliverPaymentNotices($row->id);
            }
        });
    }

    private function storePaymentNotices(array $identity, array $notices): void
    {
        foreach ($notices as $notice) DB::table('ae_stripe_outbox')->insertOrIgnore([
            'kind' => $identity['kind'] . (isset($notice['staff_scope']) ? ':staff' : ':customer'),
            'source' => $identity['source'], 'recipient_id' => $notice['user_id'],
            'payload' => json_encode($notice, JSON_THROW_ON_ERROR), 'state' => 'pending', 'attempts' => 0,
            'next_at' => time(), 'sent_at' => null, 'last_error' => null,
        ]);
    }

    private function staffPaymentNotices(string $scope, array $notice): array
    {
        $notices = [];
        foreach ($this->noticeStaff($scope) as $staff) $notices[] = ['user_id' => $staff->id, 'staff_scope' => $scope] + $notice;
        return $notices;
    }

    // The queued job contains only the durable recipient entry's id, never mail content or credentials.
    public function deliverReturnMail(int $id): void
    {
        $this->deliverPaymentNotices($id, true);
    }

    private function deliverPaymentNotices(int $id, bool $sendMail = false): void
    {
        try {
            DB::transaction(function () use ($id, $sendMail) {
                $row = DB::table('ae_stripe_outbox')->where('id', $id)->lockForUpdate()->first();
                if (!$row || $row->state !== 'pending' || $row->next_at > time()) return;
                $notice = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                // Account mail (Auto Pay is on) belongs to no invoice; the recipient alone decides delivery.
                $accountMail = str_starts_with($row->kind, 'ae_stripe_auto_pay_mail:');
                $isMail = $accountMail || str_starts_with($row->kind, 'ae_stripe_return_mail:');
                if ($isMail && !$sendMail) {
                    // Acceptance by the queue does not complete delivery. A lost enqueue keeps this entry pending.
                    DB::afterCommit(fn () => Queue::push(new DeliverReturnMail($id)));
                    return;
                }
                if ($sendMail && !$isMail) return;
                $live = false;
                foreach (User::whereIn('id', [$row->recipient_id])->get() as $recipient) $live = true;
                if ($isMail && config('settings.mail_disable')) $live = false;
                if (isset($notice['staff_scope'])) {
                    $audience = array_map(fn ($staff) => (int) $staff->id, $this->noticeStaff($notice['staff_scope']));
                    $live = $live && in_array((int) $row->recipient_id, $audience, true);
                } elseif (!$accountMail) $live = $live && isset($notice['invoice_id']) && Invoice::where('id', $notice['invoice_id'])
                    ->where('user_id', $row->recipient_id)->exists();
                if ($live) {
                    $linkLabel = $notice['link_label'] ?? 'Open invoice';
                    unset($notice['staff_scope'], $notice['invoice_id'], $notice['link_label']);
                    if ($isMail) {
                        $mail = new SystemMail(['subject' => $notice['title'], 'body' => '<p>' . e($notice['body'])
                            . '</p><p><a href="' . e($notice['url']) . '">' . e($linkLabel) . '</a></p>']);
                        $log = EmailLog::create(['user_id' => $recipient->id, 'subject' => $notice['title'],
                            'to' => $recipient->email, 'body' => $mail->render()]);
                        $mail->email_log_id = $log->id;
                        // This already runs in a queued job. Do not create a second deferred mail job.
                        Mail::to($recipient->email)->send($mail);
                        $log->update(['status' => 'sent', 'sent_at' => now()]);
                    } else Notification::create($notice);
                }
                DB::table('ae_stripe_outbox')->where('id', $id)->update([
                    'state' => $live ? 'sent' : 'skipped', 'sent_at' => time(), 'last_error' => null,
                ]);
            });
        } catch (\Throwable $e) {
            report($e);
            try {
                DB::transaction(function () use ($id) {
                    $row = DB::table('ae_stripe_outbox')->where('id', $id)->lockForUpdate()->first();
                    if (!$row || $row->state !== 'pending' || $row->next_at > time()) return;
                    $attempts = $row->attempts + 1;
                    $failed = $attempts >= 8;
                    DB::table('ae_stripe_outbox')->where('id', $id)->update([
                        'attempts' => $attempts, 'state' => $failed ? 'failed' : 'pending',
                        'next_at' => time() + min(86400, 60 * 2 ** min($attempts - 1, 11)),
                        'last_error' => 'Notification delivery failed',
                    ]);
                    if ($failed) Log::error('AeStripe notice delivery failed', ['outbox_id' => $id, 'kind' => $row->kind]);
                });
            } catch (\Throwable $retryError) { report($retryError); }
        }
    }

    private function retryPaymentNotices(): void
    {
        foreach (DB::table('ae_stripe_outbox')->where('state', 'pending')->where('next_at', '<=', time())
            ->orderBy('next_at')->orderBy('id')->limit(100)->get() as $row) $this->deliverPaymentNotices($row->id);
    }

    public function backfillPaymentNotices(): void
    {
        $events = ['ae_stripe_attempt_review', 'ae_stripe_payment_refused', 'ae_stripe_access_refused', 'ae_stripe_payment_review',
            'ae_stripe_fee_review', 'ae_stripe_receipt_return', 'ae_stripe_receipt_reversal'];
        Audit::whereIn('auditable_type', [(new Invoice)->getMorphClass(), (new Gateway)->getMorphClass()])
            ->whereIn('event', $events)->where('new_values->notice_sent', false)->orderBy('id')->chunkById(100, function ($audits) {
                foreach ($audits as $audit) DB::transaction(function () use ($audit) {
                    $notices = $audit->new_values['notices'] ?? [];
                    foreach ($notices as &$notice) {
                        $customer = $audit->event === 'ae_stripe_payment_refused'
                            || $audit->event === 'ae_stripe_receipt_return' && $notice['title'] === 'Payment return';
                        if (!$customer) $notice['staff_scope'] ??= match ($audit->event) {
                            'ae_stripe_access_refused' => 'gateway',
                            'ae_stripe_fee_review' => Invoice::find($audit->auditable_id) ? 'invoice' : 'audit',
                            default => 'invoice',
                        };
                        if ($customer) $notice['invoice_id'] = $audit->auditable_type === (new Invoice)->getMorphClass() ? (int) $audit->auditable_id : null;
                        $this->storePaymentNotices($this->noticeIdentity($audit, !$customer), [$notice]);
                    }
                    unset($notice);
                });
            });
        // Old property markers are converted once during cutover, outside the normal worker scan.
        Property::where('key', 'like', 'ae_stripe_payment_alert_%')->where('value', 'like', '%"sent":false%')
            ->orderBy('id')->chunkById(100, function ($properties) {
                foreach ($properties as $property) {
                    if (!preg_match('/^ae_stripe_payment_alert_([0-9]+)_(pi_[a-zA-Z0-9]+)$/', $property->key, $match)) continue;
                    $invoice = Invoice::find($match[1]);
                    if (!$invoice || $property->model_id != $invoice->user_id || $property->model_type !== $invoice->user->getMorphClass()) continue;
                    $this->withPaymentLock($invoice, fn () => $this->schedulePaymentReviewAlert($invoice, $match[2]));
                }
            });
    }
}
