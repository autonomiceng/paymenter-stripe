<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use Paymenter\Extensions\Gateways\AeStripe\ExtensionLocks;

use App\Models\{BillingAgreement, Gateway, Invoice, Service, User};
use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException;

require_once __DIR__ . '/../SavedMethodBusyException.php';

trait SavedMethodBindings
{
    private const BINDING_RETRY = 'Saved payment methods are being updated. Try again in a moment.';

    private function withSavedMethodBindingLock(int $userId, callable $change)
    {
        $lock = $this->savedMethodLock($userId);
        if (!$lock->get()) throw new \Paymenter\Extensions\Gateways\AeStripe\SavedMethodBusyException(self::BINDING_RETRY);
        try { return $change(); } finally { $lock->release(); }
    }

    // Call under the shared lock and user transaction, before changing any binding or consent.
    private function assertSavedMethodBindable($user, $agreement): void
    {
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        if ($this->savedMethodUnavailable($user, $agreement->external_reference, $customer, $gateway)) throw new SavedMethodRetryException(self::BINDING_RETRY);
    }

    private function savedMethodUnavailable($user, string $reference, $customer, $gateway): bool
    {
        $reservation = json_decode($user->properties()->where('key', 'ae_stripe_duplicate_' . $reference)->first()?->value ?? 'null', true);
        $index = $this->savedMethodIndex($user, $reference, $customer, $gateway);
        return (($reservation['customer'] ?? null) === $customer && ($reservation['gateway'] ?? null) == $gateway->id)
            || ($index['removed'] ?? false) || isset($index['removing']);
    }

    private function configureStaffAutoPay(): void
    {
        if (!class_exists(\Filament\Forms\Components\Select::class)) return;
        \Filament\Forms\Components\Select::configureUsing(function ($field) {
            if ($field->getName() !== 'billing_agreement_id') return;
            // An already empty select cannot distinguish None from an unrelated staff save.
            $field->hintAction(\Filament\Actions\Action::make('aeStripeAutoPayOff')->label('Turn off Auto Pay')
                ->visible(fn ($livewire) => $livewire instanceof \App\Admin\Resources\ServiceResource\Pages\EditService
                    && !$livewire->getRecord()->subscription_id
                    && ($livewire->getRecord()->properties()->where('key', self::AUTO_PAY_PENDING)->exists()
                        || $livewire->getRecord()->billingAgreement?->gateway?->extension === 'AeStripe'))
                ->action(function ($livewire) {
                    $record = $livewire->getRecord();
                    \Illuminate\Support\Facades\Gate::authorize('update', $record);
                    ExtensionLocks::transaction('user:' . $record->user_id, function () use ($record) {
                        User::where('id', $record->user_id)->firstOrFail();
                        $service = Service::where('id', $record->id)->where('user_id', $record->user_id)->lockForUpdate()->firstOrFail();
                        abort_if((bool) $service->subscription_id, 409, 'This service uses its Stripe subscription billing method.');
                        $current = $service->billingAgreement;
                        abort_if($current && $current->gateway?->extension !== 'AeStripe', 409, 'The payment method changed. Refresh this service and try again.');
                        $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
                        $service->update(['billing_agreement_id' => null]);
                    });
                    $livewire->data['billing_agreement_id'] = null;
                    \Filament\Notifications\Notification::make()->title('Auto Pay is off for this service.')->success()->send();
                }));
        });
    }

    // Replace native binding writes only for this gateway; payment execution stays in the native handler.
    private function bindNativeSavedMethod($component, string $method, callable $returnEarly): void
    {
        if ($component instanceof \App\Livewire\Services\Show && $method === 'clearBillingAgreement'
            && $component->service->properties()->where('key', self::AUTO_PAY_PENDING)->exists()) {
            ExtensionLocks::transaction('user:' . Auth::id(), function () use ($component) {
                User::where('id', Auth::id())->firstOrFail();
                $service = Service::where('id', $component->service->id)->where('user_id', Auth::id())->lockForUpdate()->firstOrFail();
                $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
                $service->update(['billing_agreement_id' => null]);
                $component->service = $service;
                $component->selectedMethod = null;
            });
            $returnEarly();
            return;
        }
        $servicePage = $component instanceof \App\Livewire\Services\Show && $method === 'updateBillingAgreement';
        $invoicePage = $component instanceof \App\Livewire\Invoices\Show && $method === 'processPayment'
            && $component->setAsDefault && $component->invoice->status === 'pending';
        if (!$servicePage && !$invoicePage) return;
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $agreement = BillingAgreement::where('ulid', $component->selectedMethod)->where('user_id', Auth::id())->where('gateway_id', $gateway->id)->first();
        if (!$agreement) return;
        try {
            $this->withSavedMethodBindingLock((int) Auth::id(), function () use ($component, $agreement, $gateway, $servicePage) {
                ExtensionLocks::transaction('user:' . Auth::id(), function () use ($component, $agreement, $gateway, $servicePage) {
                    $user = User::where('id', Auth::id())->firstOrFail();
                    $current = BillingAgreement::where('id', $agreement->id)->where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->first();
                    if (!$current || !$this->gatewayEnabled()) throw new SavedMethodRetryException(self::BINDING_RETRY);
                    $this->assertSavedMethodBindable($user, $current);
                    if ($servicePage) {
                        $service = Service::where('id', $component->service->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                        if ($service->subscription_id) throw new SavedMethodRetryException('This service uses its Stripe subscription billing method.');
                        $service->update(['billing_agreement_id' => $current->id]);
                        $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
                        $component->service = $service;
                        $component->showBillingAgreement = false;
                        return;
                    }
                    $invoice = Invoice::where('id', $component->invoice->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                    abort_unless($invoice->status === 'pending', 409);
                    $count = 0;
                    foreach ($component->recurringServices()->get() as $item) {
                        $service = Service::where('id', $item->reference_id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                        if ($service->subscription_id) continue;
                        $service->update(['billing_agreement_id' => $current->id]);
                        $service->properties()->where('key', self::AUTO_PAY_PENDING)->delete();
                        ++$count;
                    }
                    if ($count) $component->notify('Default payment method has been updated for recurring services.', 'success');
                    $component->setAsDefault = false;
                });
            });
            if ($servicePage) $returnEarly();
        } catch (SavedMethodRetryException $e) {
            $component->notify($e->getMessage(), 'warning');
            $returnEarly();
        }
    }
}
