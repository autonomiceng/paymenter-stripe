<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Exceptions\DisplayException;
use App\Models\Extension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

trait WebhookContinuity
{
    private const WEBHOOK_STATE = 'ae_stripe_webhook_continuity';
    private const WEBHOOK_OVERLAP = 72 * 3600;

    private function continuityState(): array
    {
        $value = parent::config(self::WEBHOOK_STATE);
        return $value ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [];
    }

    private function savedContinuityState(): array
    {
        $gateway = \App\Models\Gateway::where('extension', 'AeStripe')->first();
        $stored = $gateway?->settings()->where('key', self::WEBHOOK_STATE)->first()?->value;
        return $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : $this->continuityState();
    }

    public function config($key)
    {
        // Runtime uses account-checked API credentials, including while an admin save is being checked.
        if (!in_array($key, ['stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret'], true)) return parent::config($key);
        return $this->savedContinuityState()['credentials'][$key] ?? parent::config($key);
    }

    private function validWebhookSignature($payload, $header): bool
    {
        $state = $this->savedContinuityState();
        $current = $state['credentials']['stripe_webhook_secret'] ?? parent::config('stripe_webhook_secret');
        if (!$this->isValidSignature($payload, $header, $current)) {
            foreach ($state['previous'] ?? [] as $entry) {
                if (($entry['until'] === null || $entry['until'] > time()) && $this->isValidSignature($payload, $header, $entry['secret'])) return true;
            }
            return false;
        }
        if (!array_filter($state['previous'] ?? [], fn ($entry) => $entry['until'] === null)) return true;

        // Serialize proof with settings saves so an in-flight old request cannot retire a newer secret.
        return DB::transaction(function () use ($payload, $header) {
            $gateway = \App\Models\Gateway::where('extension', 'AeStripe')->lockForUpdate()->first();
            $stored = $gateway?->settings()->where('key', self::WEBHOOK_STATE)->first()?->value;
            $state = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : $this->continuityState();
            $current = $state['credentials']['stripe_webhook_secret'] ?? parent::config('stripe_webhook_secret');
            if ($this->isValidSignature($payload, $header, $current)) {
                $changed = false;
                foreach ($state['previous'] ?? [] as $index => $entry) {
                    if ($entry['until'] === null) {
                        $state['previous'][$index]['until'] = time() + self::WEBHOOK_OVERLAP;
                        $changed = true;
                    }
                }
                // Saving a secret cannot prove it works. Only its first signed delivery starts overlap.
                if ($changed && $gateway) $gateway->settings()->updateOrCreate(['key' => self::WEBHOOK_STATE], [
                    'value' => json_encode($state, JSON_THROW_ON_ERROR), 'encrypted' => true,
                ]);
                return true;
            }
            foreach ($state['previous'] ?? [] as $entry) {
                if (($entry['until'] === null || $entry['until'] > time()) && $this->isValidSignature($payload, $header, $entry['secret'])) return true;
            }
            return false;
        });
    }

    private function continuityRequest(string $key, string $method, string $path, array $data = [], ?string $idempotencyKey = null)
    {
        $headers = ['Authorization' => 'Bearer ' . $key, 'Stripe-Version' => self::API_VERSION];
        if ($idempotencyKey !== null) $headers['Idempotency-Key'] = $idempotencyKey;
        return Http::withHeaders($headers)->asForm()->connectTimeout(2)->timeout(5)->$method('https://api.stripe.com/v1' . $path, $data)->throw()->object();
    }

    private function updateWebhookContinuity(Extension $gateway): void
    {
        $failure = DB::transaction(function () use ($gateway) {
            \App\Models\Gateway::where('extension', 'AeStripe')->where('id', $gateway->id)->lockForUpdate()->firstOrFail();
            $stored = $gateway->settings()->where('key', self::WEBHOOK_STATE)->first()?->value;
            $state = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : [];
            $names = ['stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret'];
            $credentials = [];
            foreach ($names as $name) $credentials[$name] = (string) ($gateway->settings()->where('key', $name)->first()?->value ?? '');
            $revokePrevious = (bool) $gateway->settings()->where('key', 'stripe_revoke_previous_secrets')->first()?->value;
            $gateway->settings()->updateOrCreate(['key' => 'stripe_revoke_previous_secrets'], ['value' => false]);
            // Paymenter's edit hook runs after settings writes, with its previously loaded settings
            // still supplied to this instance. Keep that snapshot on the first upgraded save too.
            $old = $state['credentials'] ?? array_combine($names, array_map(fn ($name) => (string) parent::config($name), $names));
            foreach ($state['previous'] ?? [] as $entry) {
                if ($entry['until'] !== null && $entry['secret'] === $credentials['stripe_webhook_secret']) {
                    $credentials['stripe_webhook_secret'] = $old['stripe_webhook_secret'];
                    break;
                }
            }
            try {
                $key = $credentials['stripe_secret_key'];
                $mode = $this->stripeKeyMode($key);
                if (!preg_match('/^(sk|rk)_/', $key) || !str_starts_with($credentials['stripe_publishable_key'], 'pk_')) throw new DisplayException('Use a restricted or secret API key and a publishable key in their matching fields.');
                if ($this->stripeKeyMode($credentials['stripe_publishable_key']) !== $mode) throw new DisplayException('Use API and publishable keys from the same Stripe mode.');
                $account = $this->continuityRequest($key, 'get', '/account')->id ?? null;
                if (!$account) throw new DisplayException('Stripe did not identify the account. Check the API key permissions and save again.');
                $oldAccount = $state['account'] ?? null;
                if (!$oldAccount && $old['stripe_secret_key'] !== '' && $old['stripe_secret_key'] !== $key) {
                    try {
                        $oldAccount = $this->continuityRequest($old['stripe_secret_key'], 'get', '/account')->id ?? null;
                        if (!$oldAccount) throw new DisplayException('Verify the existing Stripe account before replacing its API key.');
                    } catch (\Illuminate\Http\Client\RequestException $e) {
                        if ($e->response->status() !== 401) throw $e;
                    }
                }
                $oldMode = $state['mode'] ?? ($old['stripe_secret_key'] !== '' ? $this->stripeKeyMode($old['stripe_secret_key']) : $mode);
                if ($oldAccount && ($oldAccount !== $account || $oldMode !== $mode)) throw new DisplayException('Keep this gateway on its original Stripe account and mode. Use a separate gateway for another account.');
                if (!Route::has('extensions.gateways.ae-stripe.webhook')) require __DIR__ . '/../routes.php';
                $url = route('extensions.gateways.ae-stripe.webhook');
                $endpoint = null; $after = null;
                do {
                    $page = $this->continuityRequest($key, 'get', '/webhook_endpoints', ['limit' => 100] + ($after ? ['starting_after' => $after] : []));
                    foreach ($page->data as $candidate) {
                        if ($candidate->id === ($state['endpoint'] ?? null)
                            || in_array($candidate->url, [$url, str_replace('/extensions/ae-stripe/webhook', '/extensions/stripe-saved/webhook', $url)], true)) {
                            if ($endpoint && $endpoint->id !== $candidate->id) throw new DisplayException('More than one Stripe webhook matches this gateway. Keep one delivery endpoint and save its signing secret.');
                            $endpoint = $candidate;
                        }
                    }
                    $last = end($page->data); $after = $last->id ?? null;
                    if (($page->has_more ?? false) && !$after) throw new DisplayException('Stripe returned an incomplete webhook list. Save again.');
                } while ($page->has_more ?? false);
                $events = ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.processing',
                    'setup_intent.succeeded', 'subscription_schedule.canceled', 'invoice.created', 'invoice.payment_succeeded', 'charge.updated', 'charge.succeeded'];
                $params = ['url' => $url, 'enabled_events' => $events];
                $createKey = 'ae_webhook_' . hash('sha256', $gateway->id . ':' . $account . ':' . $url);
                $createParams = $params + ['api_version' => self::API_VERSION, 'metadata' => ['ae_stripe_create_key' => $createKey]];
                if ($endpoint && $credentials['stripe_webhook_secret'] === ''
                    && ($endpoint->metadata->ae_stripe_create_key ?? null) === $createKey
                    && ($endpoint->created ?? 0) > time() - 23 * 3600) {
                    // Recover a lost creation response with its original key, never another endpoint.
                    $recovered = $this->continuityRequest($key, 'post', '/webhook_endpoints', $createParams, $createKey);
                    if (($recovered->id ?? null) === $endpoint->id) $credentials['stripe_webhook_secret'] = $recovered->secret ?? '';
                }
                if ($endpoint) {
                    if ($credentials['stripe_webhook_secret'] === '') throw new DisplayException('Copy this endpoint\'s signing secret from Stripe, then save again. Its existing deliveries have been preserved.');
                    // Editing the existing endpoint keeps its signing secret and pending deliveries.
                    $existingEvents = $endpoint->enabled_events ?? [];
                    $params['enabled_events'] = array_values(array_unique(array_merge($existingEvents, $events)));
                    if ($endpoint->url !== $url || !in_array('*', $existingEvents, true) && array_diff($events, $existingEvents)) {
                        $this->continuityRequest($key, 'post', '/webhook_endpoints/' . $endpoint->id, $params);
                    }
                } else {
                    if ($credentials['stripe_webhook_secret'] !== '' || !empty($state['endpoint'])) throw new DisplayException('The saved Stripe webhook is missing. Restore its endpoint or verify its delivery history before changing setup.');
                    $endpoint = $this->continuityRequest($key, 'post', '/webhook_endpoints', $createParams, $createKey);
                    if (empty($endpoint->id) || empty($endpoint->secret)) throw new DisplayException('Stripe did not return the new webhook details. Save again to recover the same endpoint.');
                    $credentials['stripe_webhook_secret'] = $endpoint->secret;
                }
                $previous = array_values(array_filter($state['previous'] ?? [], fn ($entry) =>
                    ($entry['until'] === null || $entry['until'] > time())
                    && $entry['secret'] !== $credentials['stripe_webhook_secret']
                    && $entry['secret'] !== $old['stripe_webhook_secret']));
                if ($old['stripe_webhook_secret'] !== '' && $old['stripe_webhook_secret'] !== $credentials['stripe_webhook_secret']) {
                    $previous[] = ['secret' => $old['stripe_webhook_secret'], 'until' => null];
                }
                if ($revokePrevious) $previous = [];
                $state = ['account' => $account, 'mode' => $mode, 'endpoint' => $endpoint->id,
                    'credentials' => $credentials, 'previous' => $previous];
                foreach ($credentials as $name => $value) $gateway->settings()->updateOrCreate(['key' => $name], [
                    'value' => $value, 'encrypted' => $name !== 'stripe_publishable_key',
                ]);
                $gateway->settings()->updateOrCreate(['key' => self::WEBHOOK_STATE], ['value' => json_encode($state, JSON_THROW_ON_ERROR), 'encrypted' => true]);
                $this->config = array_replace($this->config, $credentials, [self::WEBHOOK_STATE => json_encode($state, JSON_THROW_ON_ERROR), 'stripe_revoke_previous_secrets' => false]);
            } catch (\Throwable $e) {
                // The core edit has already persisted the submitted fields. Restore verified values.
                // Do not include Stripe exception messages: they can contain request details.
                foreach ($old as $name => $value) $gateway->settings()->updateOrCreate(['key' => $name], [
                    'value' => $value, 'encrypted' => $name !== 'stripe_publishable_key',
                ]);
                $failure = $e instanceof DisplayException ? $e->getMessage() : 'Stripe setup could not be verified. The previous credentials were kept. Check the API key permissions and save again.';
            }
            // Throw outside the transaction so restoring the previous settings is committed.
            if (isset($failure)) return $failure;
            return null;
        });
        if ($failure !== null) throw new DisplayException($failure);
    }

    private function stripeKeyMode(string $key): string
    {
        if (!preg_match('/^(?:sk|rk|pk)_(test|live)_/', $key, $match)) throw new DisplayException('Enter valid Stripe API and publishable keys from the same account and mode.');
        return $match[1];
    }
}
