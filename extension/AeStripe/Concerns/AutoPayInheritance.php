<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use Paymenter\Extensions\Gateways\AeStripe\ExtensionLocks;

use App\Models\{BillingAgreement, Service, User};
use Illuminate\Support\Facades\{Auth, DB, Log};

trait AutoPayInheritance
{
    private const AUTO_PAY_PENDING = 'ae_stripe_auto_pay_pending';

    private function invalidatePendingAutoPay(Service $service): void
    {
        if (!$service->isDirty('user_id') && !$service->isDirty('billing_agreement_id')) return;
        // Serialize cancellation before SQL, using the same row order as inheritance.
        ExtensionLocks::transaction('user:' . $service->getOriginal('user_id'), function () use ($service) {
            $ownerId = $service->getOriginal('user_id');
            User::where('id', $ownerId)->firstOrFail();
            $current = Service::where('id', $service->id)->lockForUpdate()->firstOrFail();
            if ($current->user_id != $ownerId) throw new \Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException('Service ownership changed. Refresh and try again.');
            $property = $service->properties()->where('key', self::AUTO_PAY_PENDING)->first();
            if (!$property) return;
            $pending = json_decode($property->value, true);
            if ($service->isDirty('billing_agreement_id') || ($pending['user_id'] ?? null) == $ownerId) {
                if (!$service->properties()->where('id', $property->id)->where('value', $property->value)->delete()) {
                    throw new \Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException('Auto Pay setup changed. Refresh and try again.');
                }
            }
        });
    }

    // Persist with the new service, before checkout commits. No card lock or binding belongs here.
    // A pause or active bundled Stripe still records the work; binding waits until AE Stripe takes card payments again.
    private function applyNewServiceAutoPay(Service $service): void
    {
        if ($service->billing_agreement_id || $service->subscription_id
            || ($service->plan?->type ?? null) !== 'recurring' || $service->status === Service::STATUS_CANCELLED) return;
        $owner = User::where('id', $service->user_id)->firstOrFail();
        if (!$record = $this->autoPayDefault($owner)) return;
        $value = json_encode($record, JSON_THROW_ON_ERROR);
        $service->properties()->updateOrCreate(['key' => self::AUTO_PAY_PENDING], ['value' => $value]);
        DB::afterCommit(fn () => $this->finishAutoPayInheritance((int) $owner->id, (int) $service->id, $value));
    }

    // The scheduled pass also covers a process lost between commit and its callback.
    public function retryAutoPayInheritance(): void
    {
        try { $this->pruneSavedMethodNotices(); } catch (\Throwable $e) { $this->noticeWarning('AeStripe notice cleanup will retry'); }
        Service::whereHas('properties', fn ($query) => $query->where('key', self::AUTO_PAY_PENDING))
            ->chunkById(100, function ($services) {
                foreach ($services as $service) {
                    $value = $service->properties()->where('key', self::AUTO_PAY_PENDING)->first()?->value;
                    $this->finishAutoPayInheritance((int) $service->user_id, (int) $service->id, $value);
                }
            });
    }

    private function finishAutoPayInheritance(int $ownerId, int $serviceId, ?string $value = null): void
    {
        if ($value === null) return;
        try {
            $this->withSavedMethodBindingLock($ownerId, fn () => ExtensionLocks::transaction('user:' . $ownerId, function () use ($ownerId, $serviceId, $value) {
                $user = User::where('id', $ownerId)->first();
                $service = Service::where('id', $serviceId)->lockForUpdate()->first();
                if (!$service || $service->user_id != $ownerId) return;
                $property = $service->properties()->where('key', self::AUTO_PAY_PENDING)->first();
                if (!$property || $property->value !== $value) return;
                $pending = json_decode($property->value, true);
                $record = $user ? $this->autoPayDefault($user, lock: true) : null;
                // Cleanup can move this acceptance to a verified same-card survivor without renewing consent.
                $expected = is_array($pending) && $record ? array_replace(array_diff_key($pending, ['retry' => true]), ['agreement_id' => $record['agreement_id']]) : null;
                if (!$record || $expected !== $record || ($pending['user_id'] ?? null) != $ownerId
                    || $service->billing_agreement_id || $service->subscription_id || ($service->plan?->type ?? null) !== 'recurring'
                    || $service->status === Service::STATUS_CANCELLED) {
                    $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
                    return;
                }
                if (($pending['retry']['state'] ?? null) === 'stopped' || !$this->gatewayEnabled() || !$this->bundledInactive()) return;
                $agreement = BillingAgreement::where('id', $record['agreement_id'])->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $this->assertSavedMethodBindable($user, $agreement);
                $service->update(['billing_agreement_id' => $agreement->id]);
                $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
            }));
        } catch (\Throwable $e) {
            if ($e instanceof \Paymenter\Extensions\Gateways\AeStripe\SavedMethodBusyException) return;
            try {
                if (!$this->gatewayEnabled() || !$this->bundledInactive()) return;
                // Persist bounded retry state without the busy cache lock; user then service rows still serialize opt-out.
                ExtensionLocks::transaction('user:' . $ownerId, function () use ($ownerId, $serviceId, $value) {
                    User::where('id', $ownerId)->first();
                    $service = Service::where('id', $serviceId)->where('user_id', $ownerId)->lockForUpdate()->first();
                    $property = $service?->properties()->where('key', self::AUTO_PAY_PENDING)->first();
                    if (!$property || $property->value !== $value) return;
                    $pending = json_decode($property->value, true);
                    $count = ($pending['retry']['attempts'] ?? 0) + 1;
                    $pending['retry'] = ['attempts' => $count, 'state' => $count >= 10 ? 'stopped' : 'pending'];
                    $property->update(['value' => json_encode($pending, JSON_THROW_ON_ERROR)]);
                    if ($count === 10) Log::warning('ae_stripe_auto_pay_binding_stopped', ['service_id' => $serviceId]);
                });
            } catch (\Throwable $storageError) {
                // The original pending record is still eligible for the next scheduled pass.
                try { Log::warning('ae_stripe_auto_pay_binding_pending', ['service_id' => $serviceId, 'error' => get_class($storageError)]); } catch (\Throwable $ignored) {}
            }
        }
    }

    private function deliverAutoPayPendingNotice($component, $context = null): void
    {
        $services = [];
        if ($component instanceof \App\Livewire\Services\Show) $services = [$component->service];
        elseif ($component instanceof \App\Livewire\Invoices\Show && $component->invoice->user_id == Auth::id()) {
            foreach ($component->recurringServices()->get() as $item) if ($item->reference) $services[] = $item->reference;
        }
        $states = [];
        foreach ($services as $service) {
            $property = $service->properties()->where('key', self::AUTO_PAY_PENDING)->first();
            if ($service->user_id != Auth::id() || $service->billing_agreement_id || !$property) continue;
            $pending = json_decode($property->value, true);
            if (!is_array($pending) || !in_array($pending['version'] ?? null, self::AUTO_PAY_CONSENTS, true) || empty($pending['accepted_at'])
                || ($pending['user_id'] ?? null) != Auth::id() || !is_int($pending['agreement_id'] ?? null)
                || $service->subscription_id || $service->status === Service::STATUS_CANCELLED || ($service->plan?->type ?? null) !== 'recurring') continue;
            $state = ($pending['retry']['state'] ?? null) === 'stopped' ? 'stopped'
                : (!$this->gatewayEnabled() || !$this->bundledInactive() ? 'paused' : 'pending');
            $states[$service->id] = [$state, array_diff_key($pending, ['retry' => true])];
        }
        ksort($states);
        $hash = hash('sha256', json_encode($states, JSON_THROW_ON_ERROR));
        $previous = function_exists('Livewire\\store') ? \Livewire\store($component)->get('aeStripePendingState') : null;
        $context?->addMemo('aeStripePendingState', $hash);
        if (!$states || $previous === $hash) return;
        $kinds = array_column(array_values($states), 0);
        $message = in_array('stopped', $kinds, true)
            ? 'Auto Pay could not be set up. Choose a payment method on the service, or pay invoices manually.'
            : (in_array('paused', $kinds, true)
                ? 'Auto Pay setup is paused. We will retry when card payments resume. You can still pay invoices manually.'
                : 'Auto Pay setup is pending. We will retry automatically. You can still pay invoices manually.');
        $component->notify($message, 'warning');
    }
}
