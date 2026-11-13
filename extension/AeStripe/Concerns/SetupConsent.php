<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait SetupConsent
{
    /**
     * Saving a card for later charges. v3: "Save this card" in the pay dialog, or Save card in the Add card dialog,
     * with no agreement sentence. v2 was the Add card sentence, v1 the earlier pay box and Add card line. Older
     * versions stay accepted so a form opened before an upgrade still saves.
     */
    public const SAVE_CONSENT = 'off_session_v3';
    private const SAVE_CONSENTS = ['off_session_v1', 'off_session_v2', self::SAVE_CONSENT];

    public function setupConsent(Request $request)
    {
        abort_unless($this->gatewayEnabled(), 409);
        $request->validate([
            'intent' => 'required|string|regex:/^seti_[a-zA-Z0-9]+$/|max:255',
            'consent' => 'required|accepted',
            'version' => 'sometimes|string|in:' . implode(',', self::SAVE_CONSENTS),
        ]);
        abort_unless($request->boolean('consent'), 422);
        $version = $request->input('version') ?? 'off_session_v1';
        abort_unless(in_array($version, self::SAVE_CONSENTS, true), 422);
        $intent = $this->request('get', '/setup_intents/' . $request->input('intent'));
        $user = User::findOrFail(Auth::id());
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        abort_unless(($intent->metadata->user_id ?? null) == Auth::id()
            && ($intent->metadata->is_billing_agreement ?? null) === '1'
            && $customer && ($intent->customer ?? null) === $customer, 403);
        if ($intent->status === 'requires_action') {
            abort_unless(in_array($intent->metadata->ae_stripe_setup_consent ?? null, self::SAVE_CONSENTS, true)
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
