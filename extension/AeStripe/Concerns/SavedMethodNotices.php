<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Exceptions\DisplayException;
use App\Models\{Invoice, Property, User};
use Illuminate\Support\Facades\{Auth, DB, Log};

trait SavedMethodNotices
{
    private function savedNoticeKey(string $token): string
    {
        return 'ae_stripe_notice_' . hash('sha256', $token);
    }

    private function savedNoticeReceipt($intent): string
    {
        return 'ae_stripe_saved_' . hash('sha256', $intent->id . ':' . $intent->payment_method);
    }

    // Reserve the tab destination before saving: a webhook can complete it even after this response.
    private function saveConsentedMethod($intent, ?string $token = null): ?string
    {
        $reserved = false;
        try {
            if (($intent->metadata->ae_stripe_gateway ?? null) !== '1' || ($intent->metadata->ae_stripe_consent ?? null) !== '1'
                || empty($intent->id) || empty($intent->payment_method)
                || isset($intent->created) && $intent->created < now()->timestamp - 30 * 86400) return null;
            $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
            if ($invoice->user_id != Auth::id() || ($intent->customer ?? null) !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value) return null;
            $token = is_string($token) && preg_match('/\A[a-f0-9]{32}\z/', $token) ? $token : bin2hex(random_bytes(16));
            $token = DB::transaction(function () use ($invoice, $intent, $token) {
                $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
                $current = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
                if ($current->user_id != $user->id) return null;
                foreach ($user->properties()->where('key', 'like', 'ae_stripe_notice_%')->lockForUpdate()->get() as $property) {
                    $existing = json_decode($property->value, true);
                    if (($existing['intent_id'] ?? null) === $intent->id && ($existing['invoice_id'] ?? null) == $invoice->id
                        && ($existing['user_id'] ?? null) == $user->id && isset($existing['token'])
                        && preg_match('/\A[a-f0-9]{32}\z/', $existing['token']) && $property->key === $this->savedNoticeKey($existing['token'])) return $existing['token'];
                }
                while ($property = $user->properties()->where('key', $this->savedNoticeKey($token))->lockForUpdate()->first()) {
                    $existing = json_decode($property->value, true);
                    if (($existing['invoice_id'] ?? null) == $invoice->id && ($existing['intent_id'] ?? null) === $intent->id) return $token;
                    // Stable replacement for a reused token; another intent never overwrites its destination.
                    $token = substr(hash('sha256', $token . ':' . $intent->id . ':' . $invoice->id), 0, 32);
                }
                $receiptKey = $this->savedNoticeReceipt($intent);
                $receipt = json_decode($user->properties()->where('key', $receiptKey)->lockForUpdate()->first()?->value ?? 'null', true);
                if ($receipt['notice_consumed'] ?? false) return null;
                $user->properties()->updateOrCreate(['key' => $this->savedNoticeKey($token)], ['value' => json_encode([
                    'user_id' => (int) $user->id, 'invoice_id' => (int) $invoice->id, 'intent_id' => $intent->id,
                    'token' => $token, 'receipt_key' => $receiptKey, 'created_at' => now()->timestamp, 'consumed' => false, 'state' => 'pending',
                ], JSON_THROW_ON_ERROR)]);
                return $token;
            });
            if ($token === null) return null;
            $reserved = true;
            $saved = $this->savePaidMethod($intent);
            if ($saved === true || is_array($saved)) return $token;
        } catch (DisplayException $e) {
            $this->noticeWarning('AeStripe saved method refused', ['invoice_id' => $intent->metadata->invoice_id ?? null]);
        } catch (\Throwable $e) {
            // A notice failure must never turn an already settled payment into a payment error.
            $this->noticeWarning('AeStripe saved method confirmation deferred', ['invoice_id' => $intent->metadata->invoice_id ?? null, 'error' => get_class($e)]);
            return $reserved ? $token : null;
        }
        return null;
    }

    private function discardSavedMethodNotices($intent): void
    {
        if (empty($intent->metadata->invoice_id) || empty($intent->id)) return;
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        DB::transaction(function () use ($invoice, $intent) {
            $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            foreach ($user->properties()->where('key', 'like', 'ae_stripe_notice_%')->lockForUpdate()->get() as $property) {
                $notice = json_decode($property->value, true);
                if (($notice['intent_id'] ?? null) === $intent->id && ($notice['invoice_id'] ?? null) == $invoice->id
                    && ($notice['state'] ?? null) === 'pending') $user->properties()->where('key', $property->key)->delete();
            }
        });
    }

    private function completeSavedMethodNotices($intent, $saved): void
    {
        if ($saved !== true && !is_array($saved)) return;
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        DB::transaction(function () use ($invoice, $intent, $saved) {
            $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            foreach ($user->properties()->where('key', 'like', 'ae_stripe_notice_%')->lockForUpdate()->get() as $property) {
                $notice = json_decode($property->value, true);
                if (($notice['intent_id'] ?? null) !== $intent->id || ($notice['invoice_id'] ?? null) != $invoice->id
                    || ($notice['user_id'] ?? null) != $user->id || !in_array($notice['state'] ?? null, ['pending', 'failed'], true) || ($notice['consumed'] ?? false)) continue;
                $notice['state'] = 'ready';
                $notice['notice'] = ['type' => 'success', 'message' => is_array($saved) ? $saved['message'] : 'Payment method added successfully.'];
                $user->properties()->updateOrCreate(['key' => $property->key], ['value' => json_encode($notice, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    private function savedMethodDestination($invoice, ?string $token): string
    {
        $url = route('invoices.show', $invoice);
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(['checkPayment' => 'true', 'saved_notice' => $token]);
    }

    private function savedNoticeForPage($component): ?array
    {
        if (!$component instanceof \App\Livewire\Invoices\Show || $component->invoice->user_id != Auth::id()) return null;
        if (\Livewire\store($component)->get('aeStripeNoticeDone', false)) return null;
        $token = \Livewire\store($component)->get('aeStripeSavedNotice') ?? request()->input('saved_notice');
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/', $token)) return null;
        $property = $component->invoice->user->properties()->where('key', $this->savedNoticeKey($token))->first();
        $notice = json_decode($property?->value ?? 'null', true);
        if (!is_array($notice) || ($notice['user_id'] ?? null) != Auth::id() || ($notice['invoice_id'] ?? null) != $component->invoice->id) $notice = null;
        return [$token, $property, $notice];
    }

    private function savedNoticeNeedsPolling($component): bool
    {
        $state = $this->savedNoticeForPage($component);
        return $state && ($state[2]['state'] ?? null) === 'pending' && !($state[2]['consumed'] ?? false);
    }

    private function deliverSavedMethodNotice($component, $context = null): void
    {
        if (!$state = $this->savedNoticeForPage($component)) {
            if ($context && $component instanceof \App\Livewire\Invoices\Show) {
                $context->addMemo('aeStripeNoticeDone', (bool) \Livewire\store($component)->get('aeStripeNoticeDone', false));
            }
            return;
        }
        [$token, $snapshot, $notice] = $state;
        $context?->addMemo('aeStripeSavedNotice', $token);
        $done = !$notice || ($notice['consumed'] ?? false) || ($notice['state'] ?? null) === 'failed';
        if (!$done && !empty($notice['notice'])) {
            DB::transaction(function () use ($component, $token, &$done) {
                $user = User::where('id', Auth::id())->lockForUpdate()->firstOrFail();
                $property = $user->properties()->where('key', $this->savedNoticeKey($token))->lockForUpdate()->first();
                $current = json_decode($property?->value ?? 'null', true);
                $done = true;
                if (!$current || ($current['user_id'] ?? null) != $user->id || ($current['invoice_id'] ?? null) != $component->invoice->id
                    || ($current['consumed'] ?? false) || empty($current['notice'])) return;
                $component->notify($current['notice']['message'], $current['notice']['type']);
                $current['consumed'] = true; $current['consumed_at'] = now()->timestamp;
                if (isset($current['receipt_key'])) {
                    $receipt = json_decode($user->properties()->where('key', $current['receipt_key'])->lockForUpdate()->first()?->value ?? 'null', true);
                    if (is_array($receipt)) $user->properties()->updateOrCreate(['key' => $current['receipt_key']], ['value' => json_encode(array_replace($receipt, ['notice_consumed' => true]), JSON_THROW_ON_ERROR)]);
                }
                $property->update(['value' => json_encode($current, JSON_THROW_ON_ERROR)]);
            });
        }
        $context?->addMemo('aeStripeNoticeDone', $done);
    }

    // Recheck the exact reservation under the owner lock after every provider read.
    private function changeSavedNotice($snapshot, callable $change): void
    {
        DB::transaction(function () use ($snapshot, $change) {
            $user = User::where('id', $snapshot->model_id)->lockForUpdate()->first();
            $current = $user?->properties()->where('key', $snapshot->key)->lockForUpdate()->first();
            if (!$current || $current->value !== $snapshot->value) return;
            $notice = json_decode($current->value, true);
            $next = $change($user, is_array($notice) ? $notice : [], $current);
            if ($next === null) $user->properties()->where('key', $current->key)->delete();
            else $current->update(['value' => json_encode($next, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
    }

    private function noticeWarning(string $message, array $context = []): void
    {
        try { Log::warning($message, $context); } catch (\Throwable $e) {} // Notice housekeeping cannot fail a settled payment.
    }

    private function pruneSavedMethodNotices(): void
    {
        foreach (Property::where('key', 'like', 'ae_stripe_notice_%')->where('model_type', (new User)->getMorphClass())
            ->orderBy('updated_at')->orderBy('id')->limit(200)->get() as $property) {
            $this->changeSavedNotice($property, function ($user, $notice, $current) {
                $created = $notice['created_at'] ?? $current->created_at->timestamp;
                if (!is_numeric($created) || $created >= now()->timestamp - 30 * 86400) return $notice;
                if (isset($notice['receipt_key'])) {
                    $receipt = json_decode($user->properties()->where('key', $notice['receipt_key'])->lockForUpdate()->first()?->value ?? 'null', true);
                    if (is_array($receipt)) $user->properties()->updateOrCreate(['key' => $notice['receipt_key']],
                        ['value' => json_encode(array_replace($receipt, ['notice_consumed' => true]), JSON_THROW_ON_ERROR)]);
                }
                return null; // Retire only notice metadata; payment and saving receipts remain intact.
            });
        }
    }

    private function retrySavedMethodNotices(): void
    {
        if (!$this->gatewayEnabled() || !$this->bundledInactive()) return;
        foreach (Property::where('key', 'like', 'ae_stripe_notice_%')->where('model_type', (new User)->getMorphClass())
            ->where('value', 'like', '%"state"%"pending"%')->orderBy('updated_at')->orderBy('id')->limit(200)->get() as $property) {
            try {
                $notice = json_decode($property->value, true);
                if (is_array($notice) && ($notice['state'] ?? null) !== 'pending') {
                    $this->changeSavedNotice($property, fn ($user, $record) => $record);
                    continue;
                }
                $invoice = is_array($notice) ? Invoice::find($notice['invoice_id'] ?? null) : null;
                if (!$invoice || $invoice->user_id != $property->model_id || ($notice['user_id'] ?? null) != $invoice->user_id
                    || !is_string($notice['intent_id'] ?? null) || !preg_match('/\Api_[a-zA-Z0-9_]+\z/', $notice['intent_id'])) {
                    $this->changeSavedNotice($property, fn () => null);
                    continue;
                }
                if (($notice['retry_at'] ?? 0) > now()->timestamp) {
                    $this->changeSavedNotice($property, fn ($user, $record) => $record);
                    continue;
                }
                $intent = $this->request('get', '/payment_intents/' . $notice['intent_id']);
                $confirmed = ($intent->id ?? null) === $notice['intent_id'] && ($intent->metadata->invoice_id ?? null) == $invoice->id && ($intent->status ?? null) === 'succeeded';
                if ($confirmed) {
                    $this->savePaidMethod($intent);
                }
                $readable = is_object($intent) && is_string($intent->id ?? null) && is_string($intent->status ?? null);
                $this->deferSavedNotice($property, consumeAttempt: $readable && !$confirmed);
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $missing = $e->response->status() === 404 && $e->response->json('error.code') === 'resource_missing';
                $this->deferSavedNotice($property, $missing ? 86400 : null, consumeAttempt: $missing);
                if (!$missing) break; // Access problems/rate limits/outages must not fan out across customers.
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $this->deferSavedNotice($property); break;
            } catch (\Throwable $e) {
                $this->deferSavedNotice($property);
                $this->noticeWarning('AeStripe saved method confirmation pending');
            }
        }
    }

    private function deferSavedNotice($property, ?int $delay = null, bool $consumeAttempt = false): void
    {
        try { $this->changeSavedNotice($property, function ($user, $notice) use ($delay, $consumeAttempt) {
            if (($notice['state'] ?? null) !== 'pending') return $notice;
            // Only the intent's own missing/mismatched/non-success state consumes its budget.
            $attempts = ($notice['attempts'] ?? 0) + (int) $consumeAttempt;
            $next = ['retry_at' => now()->timestamp + ($delay ?? min(3600, 60 * 2 ** min($attempts, 6)))];
            if ($consumeAttempt) {
                $next += ['attempts' => $attempts, 'state' => $attempts >= 20 ? 'failed' : 'pending'];
                if ($attempts === 20) DB::afterCommit(fn () => $this->noticeWarning('AeStripe saved method confirmation recovery exhausted; check Stripe account access',
                    ['invoice_id' => $notice['invoice_id'] ?? null, 'intent_id' => $notice['intent_id'] ?? null]));
            }
            return array_replace($notice, $next);
        }); } catch (\Throwable $e) { $this->noticeWarning('AeStripe confirmation retry state will retry'); }
    }
}
