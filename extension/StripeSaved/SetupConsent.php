<?php

namespace Paymenter\Extensions\Gateways\StripeSaved;

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
        ]);
        abort_unless($request->boolean('consent'), 422);
        $intent = $this->request('get', '/setup_intents/' . $request->input('intent'));
        $user = User::findOrFail(Auth::id());
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        abort_unless(($intent->metadata->user_id ?? null) == Auth::id()
            && ($intent->metadata->is_billing_agreement ?? null) === '1'
            && $customer && ($intent->customer ?? null) === $customer, 403);
        abort_unless(in_array($intent->status, ['requires_payment_method', 'requires_confirmation']), 409);
        // Record the versioned terms and authenticated acceptance before confirmation.
        $this->request('post', '/setup_intents/' . $intent->id, ['metadata' => [
            'stripe_saved_setup_consent' => 'off_session_v1',
            'stripe_saved_setup_consent_at' => (string) time(),
            'stripe_saved_setup_consent_user' => (string) Auth::id(),
        ]]);
        return response()->json(['ok' => true]);
    }
}
