<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{BillingAgreement, Service, User};
use Illuminate\Support\Facades\{Auth, DB, Log};

trait AutoPayInheritance
{
    private const AUTO_PAY_PENDING = 'ae_stripe_auto_pay_pending';

    // Persist with the new service, before checkout commits. No card lock or binding belongs here.
    private function applyNewServiceAutoPay(Service $service): void
    {
        if (!$this->gatewayEnabled() || !$this->bundledInactive() || $service->billing_agreement_id || $service->subscription_id
            || ($service->plan?->type ?? null) !== 'recurring' || $service->status === Service::STATUS_CANCELLED) return;
        $owner = User::where('id', $service->user_id)->firstOrFail();
        if (!$record = $this->autoPayDefault($owner)) return;
        $service->properties()->updateOrCreate(['key' => self::AUTO_PAY_PENDING], ['value' => json_encode($record, JSON_THROW_ON_ERROR)]);
        DB::afterCommit(fn () => $this->finishAutoPayInheritance((int) $owner->id, (int) $service->id));
    }

    // The scheduled pass also covers a process lost between commit and its callback.
    public function retryAutoPayInheritance(): void
    {
        Service::whereHas('properties', fn ($query) => $query->where('key', self::AUTO_PAY_PENDING))
            ->chunkById(100, function ($services) {
                foreach ($services as $service) $this->finishAutoPayInheritance((int) $service->user_id, (int) $service->id);
            });
    }

    private function finishAutoPayInheritance(int $ownerId, int $serviceId): void
    {
        try {
            $this->withSavedMethodBindingLock($ownerId, fn () => DB::transaction(function () use ($ownerId, $serviceId) {
                $user = User::where('id', $ownerId)->lockForUpdate()->first();
                $service = Service::where('id', $serviceId)->lockForUpdate()->first();
                if (!$service) return;
                $property = $service->properties()->where('key', self::AUTO_PAY_PENDING)->lockForUpdate()->first();
                if (!$property) return;
                $pending = json_decode($property->value, true);
                $record = $user && $this->gatewayEnabled() && $this->bundledInactive() ? $this->autoPayDefault($user, lock: true) : null;
                if (!$record || $pending !== $record || $service->user_id != $ownerId || ($pending['user_id'] ?? null) != $ownerId
                    || $service->billing_agreement_id || $service->subscription_id || ($service->plan?->type ?? null) !== 'recurring'
                    || $service->status === Service::STATUS_CANCELLED) {
                    $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
                    return;
                }
                $agreement = BillingAgreement::where('id', $record['agreement_id'])->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $this->assertSavedMethodBindable($user, $agreement);
                $service->update(['billing_agreement_id' => $agreement->id]);
                $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
            }));
        } catch (\Throwable $e) {
            // After-commit callbacks must never turn a committed order into a checkout error.
            Log::warning('ae_stripe_auto_pay_binding_pending', ['service_id' => $serviceId, 'error' => get_class($e)]);
        }
    }

    private function deliverAutoPayPendingNotice($component): void
    {
        if (!$component instanceof \App\Livewire\Services\Show || $component->service->user_id != Auth::id()) return;
        $service = $component->service;
        if ($service->billing_agreement_id || !$service->properties()->where('key', self::AUTO_PAY_PENDING)->exists()) return;
        $component->notify('Auto Pay setup is pending. We will retry automatically. You can still pay invoices manually.', 'warning');
    }
}
