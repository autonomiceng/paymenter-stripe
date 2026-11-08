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
            Log::warning('AeStripe saved method refused', ['invoice_id' => $intent->metadata->invoice_id ?? null]);
        } catch (\Throwable $e) {
            // A notice failure must never turn an already settled payment into a payment error.
            Log::warning('AeStripe saved method confirmation deferred', ['invoice_id' => $intent->metadata->invoice_id ?? null, 'error' => get_class($e)]);
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
                    || ($notice['user_id'] ?? null) != $user->id || ($notice['state'] ?? null) !== 'pending' || ($notice['consumed'] ?? false)) continue;
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

    private function deliverSavedMethodNotice($component, $context = null): void
    {
        if (!$component instanceof \App\Livewire\Invoices\Show) return;
        $invoice = $component->invoice;
        $owner = Auth::id();
        if ($invoice->user_id != $owner) return;
        // Livewire's signed memo keeps the destination across polls without copying it to another tab.
        $token = function_exists('Livewire\\store') ? \Livewire\store($component)->get('aeStripeSavedNotice') : null;
        $token ??= request()->input('saved_notice');
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/', $token)) return;
        $context?->addMemo('aeStripeSavedNotice', $token);
        DB::transaction(function () use ($component, $invoice, $owner, $token) {
            $user = User::where('id', $owner)->lockForUpdate()->firstOrFail();
            $property = $user->properties()->where('key', $this->savedNoticeKey($token))->lockForUpdate()->first();
            $notice = json_decode($property?->value ?? 'null', true);
            if (!$notice || ($notice['user_id'] ?? null) != $owner || ($notice['invoice_id'] ?? null) != $invoice->id
                || ($notice['consumed'] ?? false) || empty($notice['notice'])) return;
            $component->notify($notice['notice']['message'], $notice['notice']['type']);
            $notice['consumed'] = true; $notice['consumed_at'] = now()->timestamp;
            if (isset($notice['receipt_key'])) {
                $receipt = json_decode($user->properties()->where('key', $notice['receipt_key'])->lockForUpdate()->first()?->value ?? 'null', true);
                if ($receipt) $user->properties()->updateOrCreate(['key' => $notice['receipt_key']], ['value' => json_encode(array_replace($receipt, ['notice_consumed' => true]), JSON_THROW_ON_ERROR)]);
            }
            $user->properties()->updateOrCreate(['key' => $property->key], ['value' => json_encode($notice, JSON_THROW_ON_ERROR)]);
        });
    }

    private function pruneSavedMethodNotices(): void
    {
        foreach (Property::where('key', 'like', 'ae_stripe_notice_%')->where('value->consumed', true)
            ->where('updated_at', '<', now()->subDays(30))->orderBy('id')->limit(100)->get() as $property) {
            if ($property->model_type !== (new User)->getMorphClass()) continue;
            DB::transaction(function () use ($property) {
                $user = User::where('id', $property->model_id)->lockForUpdate()->first();
                $current = $user?->properties()->where('key', $property->key)->lockForUpdate()->first();
                $notice = json_decode($current?->value ?? 'null', true);
                if (($notice['consumed'] ?? false) && ($notice['consumed_at'] ?? $current->updated_at->timestamp) < now()->timestamp - 30 * 86400) $user->properties()->where('key', $property->key)->delete();
            });
        }
    }

    private function retrySavedMethodNotices(): void
    {
        if (!$this->gatewayEnabled() || !$this->bundledInactive()) return;
        foreach (Property::where('key', 'like', 'ae_stripe_notice_%')->where('value->state', 'pending')->orderBy('updated_at')->limit(100)->get() as $property) {
            if ($property->model_type !== (new User)->getMorphClass()) continue;
            $notice = json_decode($property->value, true);
            $invoice = Invoice::find($notice['invoice_id'] ?? null);
            if (!$invoice || $invoice->user_id != $property->model_id || ($notice['user_id'] ?? null) != $invoice->user_id || empty($notice['intent_id'])) continue;
            try {
                $intent = $this->request('get', '/payment_intents/' . $notice['intent_id']);
                if ($intent->id === $notice['intent_id'] && ($intent->metadata->invoice_id ?? null) == $invoice->id && $intent->status === 'succeeded') $this->savePaidMethod($intent);
            } catch (\Illuminate\Http\Client\RequestException | \Illuminate\Http\Client\ConnectionException $e) { break; }
            catch (\Throwable $e) { Log::warning('AeStripe saved method confirmation pending', ['invoice_id' => $invoice->id]); }
        }
    }
}
