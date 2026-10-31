<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{BillingAgreement, Gateway, Invoice, Service, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};

trait AutoPay
{
    public const AUTO_PAY_CONSENT = 'auto_pay_new_services_v2';
    private const AUTO_PAY_PROPERTY = 'ae_stripe_auto_pay_default';

    /** A saved card or v1 consent alone never grants this scope. */
    public function autoPayDefault(User $user, bool $lock = false): ?array
    {
        $property = $user->properties()->where('key', self::AUTO_PAY_PROPERTY);
        $value = ($lock ? $property->lockForUpdate() : $property)->first()?->value;
        $record = $value ? json_decode($value, true) : null;
        if (!is_array($record) || ($record['version'] ?? null) !== self::AUTO_PAY_CONSENT
            || ($record['user_id'] ?? null) != $user->id || empty($record['accepted_at'])
            || !is_int($record['agreement_id'] ?? null)) return null;
        $gateway = Gateway::where('extension', 'AeStripe')->first();
        $query = $gateway ? BillingAgreement::where('id', $record['agreement_id'])
            ->where('user_id', $user->id)->where('gateway_id', $gateway->id) : null;
        $agreement = $query ? ($lock ? $query->lockForUpdate() : $query)->first() : null;
        return $agreement ? $record : null;
    }

    /** Authenticated account preference, independent of paying any invoice. Off needs no consent. */
    public function saveAutoPayDefault(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
            'agreement_id' => 'required_if:enabled,true|integer|min:1',
            'consent' => 'accepted_if:enabled,true',
            'version' => 'required_if:enabled,true|string',
        ]);
        abort_unless(!$request->boolean('enabled') || $this->gatewayEnabled(), 409);
        return DB::transaction(function () use ($request) {
            $user = User::where('id', Auth::id())->lockForUpdate()->firstOrFail();
            if (!$request->boolean('enabled')) {
                $user->properties()->where('key', self::AUTO_PAY_PROPERTY)->delete();
                return response()->json(['ok' => true, 'enabled' => false]);
            }
            abort_unless($request->boolean('consent') && $request->input('version') === self::AUTO_PAY_CONSENT, 422);
            $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
            $agreement = BillingAgreement::where('id', $request->input('agreement_id'))
                ->where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->first();
            abort_unless($agreement, 403);
            $record = ['version' => self::AUTO_PAY_CONSENT, 'user_id' => (int) $user->id,
                'accepted_at' => time(), 'agreement_id' => (int) $agreement->id];
            $user->properties()->updateOrCreate(['key' => self::AUTO_PAY_PROPERTY], ['value' => json_encode($record, JSON_THROW_ON_ERROR)]);
            return response()->json(['ok' => true, 'enabled' => true]);
        });
    }

    /** Called synchronously by Service Created only. Never backfill or reapply on Updated. */
    private function applyNewServiceAutoPay(Service $service): void
    {
        if (!$this->gatewayEnabled() || !$this->bundledInactive()) return;
        DB::transaction(function () use ($service) {
            $user = User::where('id', $service->user_id)->lockForUpdate()->firstOrFail();
            $current = Service::where('id', $service->id)->lockForUpdate()->firstOrFail();
            if ($current->user_id != $user->id || $current->billing_agreement_id || $current->subscription_id
                || ($current->plan?->type ?? null) !== 'recurring' || $current->status === Service::STATUS_CANCELLED) return;
            $record = $this->autoPayDefault($user, lock: true);
            if ($record) $current->update(['billing_agreement_id' => $record['agreement_id']]);
        });
    }

    /** Local read-only dates for account, service and invoice opt-ins. No provider calls. */
    public function autoPayDates(Service $service): array
    {
        $dates = ['service_id' => (int) $service->id, 'invoice_on' => null, 'charge_on' => null,
            'charge_timing' => 'invoice_creation', 'state' => 'not_scheduled'];
        if (($service->plan?->type ?? null) !== 'recurring' || $service->status !== 'active'
            || $service->cancellation || $service->price <= 0) return $dates;
        $dates['state'] = 'missing';
        $pending = $service->invoices()->where('status', 'pending')->first();
        if ($pending) return array_replace($dates, ['state' => 'existing_invoice',
            'invoice_on' => $pending->created_at?->format('Y-m-d'), 'invoice_id' => (int) $pending->id]);
        if ($service->product?->server?->extension === 'Domains') {
            // Use Domains' calendar, never the hosting cron offset or a watched hosting domain.
            $presenter = \Paymenter\Extensions\Servers\Domains\Services\NativePresenter::class;
            if (!class_exists($presenter) || !app()->bound($presenter)) return $dates;
            $adapter = app($presenter);
            $domain = $adapter->service($service->user, (int) $service->id);
            if (!$domain) return $dates;
            if (isset($domain->renewNow['invoice'])) return array_replace($dates, ['state' => 'existing_invoice',
                'invoice_url' => $domain->renewNow['invoice']]);
            if ($domain->renewal !== 'On') return array_replace($dates, ['state' => $domain->renewal === 'Off' ? 'not_scheduled' : 'missing']);
            $summary = $adapter->domainSummaries($service->user, [(int) $service->id])[$service->id] ?? null;
            $issue = $summary?->renewalIssueAt;
        } else {
            $expiry = $service->expires_at;
            $issue = $expiry ? \DateTimeImmutable::createFromInterface($expiry)
                ->modify(sprintf('%+d days', -(int) config('settings.cronjob_invoice', 7)))->format('Y-m-d') : null;
        }
        if (!$issue) return $dates;
        // An overdue calendar date is not a promise to charge in the past.
        $today = now()->format('Y-m-d');
        return array_replace($dates, ['invoice_on' => $issue, 'charge_on' => $issue > $today ? $issue : null,
            'state' => $issue <= $today ? 'next_billing_run' : 'scheduled']);
    }

    public function invoiceAutoPayDates(Invoice $invoice): array
    {
        $dates = [];
        foreach ($invoice->items as $item) {
            if ($item->reference_type !== (new Service)->getMorphClass()) continue;
            $service = $item->reference;
            if ($service && $service->user_id == $invoice->user_id && ($service->plan?->type ?? null) === 'recurring') {
                $dates[$service->id] = $this->autoPayDates($service);
            }
        }
        return $dates;
    }
}
