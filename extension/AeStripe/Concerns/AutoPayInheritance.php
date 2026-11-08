<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{BillingAgreement, Service, User};
use Illuminate\Support\Facades\{Auth, DB, Log};

trait AutoPayInheritance
{
    private const AUTO_PAY_PENDING = 'ae_stripe_auto_pay_pending';

    private function invalidatePendingAutoPay(Service $service): void
    {
        if (!$service->isDirty('user_id') && !$service->isDirty('billing_agreement_id')) return;
        // Cancel in the native write, including transfers away and back; leave newer owners' work alone.
        $property = $service->properties()->where('key', self::AUTO_PAY_PENDING)->first();
        if (!$property) return;
        $pending = json_decode($property->value, true);
        if ($service->isDirty('billing_agreement_id') || ($pending['user_id'] ?? null) == $service->getOriginal('user_id')) {
            $service->properties()->where('id', $property->id)->where('value', $property->value)->delete();
        }
    }

    // Persist with the new service, before checkout commits. No card lock or binding belongs here.
    private function applyNewServiceAutoPay(Service $service): void
    {
        if (!$this->gatewayEnabled() || !$this->bundledInactive() || $service->billing_agreement_id || $service->subscription_id
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
        $this->pruneSavedMethodNotices();
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
        if ($value === null || (json_decode($value, true)['retry']['state'] ?? null) === 'stopped') return;
        try {
            $this->withSavedMethodBindingLock($ownerId, fn () => DB::transaction(function () use ($ownerId, $serviceId, $value) {
                $user = User::where('id', $ownerId)->lockForUpdate()->first();
                $service = Service::where('id', $serviceId)->lockForUpdate()->first();
                if (!$service || $service->user_id != $ownerId) return;
                $property = $service->properties()->where('key', self::AUTO_PAY_PENDING)->lockForUpdate()->first();
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
                if (!$this->gatewayEnabled() || !$this->bundledInactive()) return;
                $agreement = BillingAgreement::where('id', $record['agreement_id'])->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $this->assertSavedMethodBindable($user, $agreement);
                $service->update(['billing_agreement_id' => $agreement->id]);
                $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
            }));
        } catch (\Throwable $e) {
            if (!$this->gatewayEnabled() || !$this->bundledInactive()) return;
            try {
                // Persist bounded retry state without the busy cache lock; user then service rows still serialize opt-out.
                DB::transaction(function () use ($ownerId, $serviceId, $value) {
                    User::where('id', $ownerId)->lockForUpdate()->first();
                    $service = Service::where('id', $serviceId)->where('user_id', $ownerId)->lockForUpdate()->first();
                    $property = $service?->properties()->where('key', self::AUTO_PAY_PENDING)->lockForUpdate()->first();
                    if (!$property || $property->value !== $value) return;
                    $pending = json_decode($property->value, true);
                    $count = ($pending['retry']['attempts'] ?? 0) + 1;
                    $pending['retry'] = ['attempts' => $count, 'state' => $count >= 10 ? 'stopped' : 'pending'];
                    $property->update(['value' => json_encode($pending, JSON_THROW_ON_ERROR)]);
                    if ($count === 10) Log::warning('ae_stripe_auto_pay_binding_stopped', ['service_id' => $serviceId]);
                });
            } catch (\Throwable $storageError) {
                // The original pending record is still eligible for the next scheduled pass.
                Log::warning('ae_stripe_auto_pay_binding_pending', ['service_id' => $serviceId, 'error' => get_class($storageError)]);
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
