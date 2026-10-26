<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait SetupIntent
{
    private function accountSetupIntent($user, array $data)
    {
        $property = 'ae_stripe_setup';
        $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $reserve = function (?string $completedKey = null) use ($user, $property, $digest) {
            return DB::transaction(function () use ($user, $property, $digest, $completedKey) {
                $owner = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                $stored = $owner->properties()->where('key', $property)->first()?->value;
                $attempt = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
                if ($attempt && $attempt['key'] !== $completedKey) {
                    abort_unless(isset($attempt['intent']) || $attempt['digest'] === $digest, 409);
                    return $attempt;
                }
                $attempt = ['key' => 'setup_' . bin2hex(random_bytes(16)), 'digest' => $digest, 'created' => time()];
                $owner->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
                return $attempt;
            });
        };
        $attempt = $reserve();
        while (isset($attempt['intent'])) {
            $intent = $this->request('get', '/setup_intents/' . $attempt['intent']);
            if (in_array($intent->status, ['succeeded', 'canceled'], true)) {
                // Only rotate the terminal attempt we inspected; another opener may have advanced it.
                $attempt = $reserve($attempt['key']);
                continue;
            }
            abort_unless($intent->customer === $data['customer']
                && ($intent->metadata->user_id ?? null) == $user->id
                && ($intent->metadata->is_billing_agreement ?? null) === '1'
                && ($intent->metadata->ae_stripe_gateway ?? null) === '1', 409);
            abort_unless(in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true), 409);
            return $intent;
        }
        // Commit the retry key before HTTP. An ambiguous response must never create a second intent.
        abort_unless(time() - $attempt['created'] < 23 * 3600, 409);
        $intent = $this->request('post', '/setup_intents', $data, $attempt['key']);
        DB::transaction(function () use ($user, $property, $attempt, $intent) {
            $owner = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $current = json_decode($owner->properties()->where('key', $property)->first()->value, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($current['key'] === $attempt['key'], 409);
            $owner->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($attempt + ['intent' => $intent->id], JSON_THROW_ON_ERROR)]);
        });
        return $intent;
    }
}
