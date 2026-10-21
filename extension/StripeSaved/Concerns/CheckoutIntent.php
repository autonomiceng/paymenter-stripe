<?php

namespace Paymenter\Extensions\Gateways\StripeSaved\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait CheckoutIntent
{
    private function checkoutIntent($invoice, array $data)
    {
        // User properties are native storage. Commit the key before contacting Stripe so a
        // lost response cannot roll it back; serialize competing render requests on the user.
        $property = 'stripe_saved_checkout_' . $invoice->id;
        $attempt = DB::transaction(function () use ($invoice, $data, $property) {
            $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            $stored = $user->properties()->where('key', $property)->first()?->value;
            $attempt = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
            $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($attempt) {
                abort_unless($attempt['digest'] === $digest, 409);
                return $attempt;
            }
            $attempt = ['key' => 'checkout_' . bin2hex(random_bytes(16)), 'digest' => $digest, 'created' => time()];
            $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
            return $attempt;
        });
        if (isset($attempt['intent'])) {
            $intent = $this->request('get', '/payment_intents/' . $attempt['intent']);
            abort_unless(in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action']), 409);
            return $intent;
        }
        // Stripe may prune keys after 24 hours: an unresolved older attempt needs reconciliation.
        abort_unless(time() - $attempt['created'] < 23 * 3600, 409);
        $intent = $this->request('post', '/payment_intents', $data, $attempt['key']);
        DB::transaction(function () use ($invoice, $property, $attempt, $intent) {
            $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode(
                $attempt + ['intent' => $intent->id], JSON_THROW_ON_ERROR)]);
        });
        return $intent;
    }
}
