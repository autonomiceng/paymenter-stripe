<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait SetupConsent
{
    public function setupConsent(Request $request)
    {
        abort_unless($this->gatewayEnabled(), 409);
        $request->validate([
            'intent' => 'required|string|regex:/^seti_[a-zA-Z0-9]+$/|max:255',
            'consent' => 'required|accepted',
            'version' => 'sometimes|string|in:off_session_v1,off_session_v2',
        ]);
        abort_unless($request->boolean('consent'), 422);
        $version = $request->input('version') ?? 'off_session_v1';
        abort_unless(in_array($version, ['off_session_v1', 'off_session_v2'], true), 422);
        $intent = $this->request('get', '/setup_intents/' . $request->input('intent'));
        $user = User::findOrFail(Auth::id());
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        abort_unless(($intent->metadata->user_id ?? null) == Auth::id()
            && ($intent->metadata->is_billing_agreement ?? null) === '1'
            && $customer && ($intent->customer ?? null) === $customer, 403);
        if ($intent->status === 'requires_action') {
            abort_unless(in_array($intent->metadata->ae_stripe_setup_consent ?? null, ['off_session_v1', 'off_session_v2'], true)
                && !empty($intent->metadata->ae_stripe_setup_consent_at)
                && ($intent->metadata->ae_stripe_setup_consent_user ?? null) == Auth::id(), 409);
            return response()->json(['ok' => true]);
        }
        abort_unless(in_array($intent->status, ['requires_payment_method', 'requires_confirmation']), 409);
        // Record the versioned terms and authenticated acceptance before confirmation.
        $this->request('post', '/setup_intents/' . $intent->id, ['metadata' => [
            'ae_stripe_setup_consent' => $version,
            'ae_stripe_setup_consent_at' => (string) time(),
            'ae_stripe_setup_consent_user' => (string) Auth::id(),
        ]]);
        return response()->json(['ok' => true]);
    }
}
