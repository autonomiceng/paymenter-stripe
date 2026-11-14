<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Staff set the Auto Pay card: only a card of that customer, never turning Auto Pay on, keeping the customer's consent.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

(function () use ($user, $gateway) {
$oldAgreements = ExtensionHelper::$agreements;
$oldProperties = $user->props->values;
$oldEnabled = Gateway::$enabled;
$oldBundled = Gateway::$bundled;
$oldBundledEnabled = Gateway::$bundledEnabled;
$oldService = Service::$row;
$oldAuth = Auth::$id;
Gateway::$enabled = true;
Gateway::$bundled = Gateway::$bundledEnabled = false;
Auth::$id = 7;
$gatewayId = Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
$card = fn (int $id, int $owner = 7) => ['id' => $id, 'user_id' => $owner, 'gateway_id' => $gatewayId, 'external_reference' => 'pm_staff_' . $id, 'deleted_at' => null];
ExtensionHelper::$agreements = ['pm_staff_81' => $card(81), 'pm_staff_82' => $card(82), 'pm_staff_83' => $card(83, 8)];
$agreement = fn (int $id) => BillingAgreement::where('id', $id)->first() ?? (object) ['id' => $id];
$service = new Service;
$service->subscription_id = null;
$service->plan->type = 'recurring';
$service->billing_agreement_id = null;
Service::$row = $service;

// Earlier files restore the user's properties, so the Auto Pay count restarts; start from an empty outbox too.
$oldOutbox = DB::$tables['ae_stripe_outbox'] ?? [];
DB::$tables['ae_stripe_outbox'] = [];
$mails = fn (string $kind) => array_values(array_filter(DB::$tables['ae_stripe_outbox'] ?? [],
    fn ($row) => $row['kind'] === 'ae_stripe_auto_pay_mail:' . $kind . ':customer' && $row['recipient_id'] == 7));
$onBefore = count($mails('on'));

// Off, without the customer's agreement: staff only save the card choice. Nothing is charged and no service is linked.
$result = $gateway->staffSetAutoPayCard($user, $agreement(81), 5);
check($result === ['enabled' => false, 'turned_on' => false, 'services' => 0] && $gateway->autoPayDefault($user) === null
    && $gateway->autoPayCard($user) === 81 && $service->billing_agreement_id === null,
    'Staff cannot turn Auto Pay on; with it off they only pick the card');

// Another customer's card is refused and changes nothing.
rejects(fn () => $gateway->staffSetAutoPayCard($user, $agreement(83), 5), 'HTTP 403');
check($gateway->autoPayCard($user) === 81, 'Another customer\'s card cannot become the Auto Pay card');

// On (the customer's own consent): staff move it to another of the customer's cards. Consent version and time stay.
$gateway->saveAutoPayDefault(new Request(['enabled' => true, 'agreement_id' => 81, 'consent' => true, 'version' => AeStripe::AUTO_PAY_CONSENT]));
$before = $gateway->autoPayDefault($user);
$result = $gateway->staffSetAutoPayCard($user, $agreement(82), 5);
$after = $gateway->autoPayDefault($user);
check($result['enabled'] === true && $after['agreement_id'] === 82 && $service->billing_agreement_id === 82,
    'With Auto Pay on, staff move renewals to the chosen card');
check($after['accepted_at'] === $before['accepted_at'] && $after['version'] === $before['version']
    && $after['card_set_by'] === 5 && is_int($after['card_set_at']), 'The customer\'s consent stays and the record names the staff member');
$cardMail = $mails('card');
check(count($cardMail) === 1 && str_contains(json_decode($cardMail[0]['payload'], true)['body'], 'Our team changed your Auto Pay card to')
    && count($mails('on')) === $onBefore + 1, 'A staff card change sends one card-changed email, not another Auto Pay is on email');
rejects(fn () => $gateway->staffSetAutoPayCard($user, $agreement(83), 5), 'HTTP 403');
check($gateway->autoPayDefault($user)['agreement_id'] === 82 && $service->billing_agreement_id === 82,
    'A refused card leaves Auto Pay on the current card');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));

// Off, with the customer's agreement on the call: Auto Pay turns on, consent recorded as by phone via the staff member.
$onBefore = count($mails('on'));
$result = $gateway->staffSetAutoPayCard($user, $agreement(81), 6, customerAgreed: true);
$record = $gateway->autoPayDefault($user);
check($result['enabled'] === true && $result['turned_on'] === true && $record['agreement_id'] === 81 && $service->billing_agreement_id === 81
    && $record['version'] === AeStripe::AUTO_PAY_CONSENT && $record['consent_via'] === 'phone' && $record['consent_staff'] === 6
    && abs($record['accepted_at'] - time()) < 5, 'With the customer\'s agreement on the call, staff turn Auto Pay on and the consent names the call and staff member');
check(count($mails('on')) === $onBefore + 1, 'A phone turn-on sends the existing Auto Pay is on email');
rejects(fn () => $gateway->staffSetAutoPayCard($user, $agreement(83), 6, customerAgreed: true), 'HTTP 403');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
Gateway::$enabled = false;
rejects(fn () => $gateway->staffSetAutoPayCard($user, $agreement(81), 6, customerAgreed: true), 'HTTP 409');
check($gateway->autoPayDefault($user) === null, 'Auto Pay cannot be turned on by phone while card payments are paused');

DB::$tables['ae_stripe_outbox'] = $oldOutbox;
ExtensionHelper::$agreements = $oldAgreements;
$user->props->values = $oldProperties;
Gateway::$enabled = $oldEnabled;
Gateway::$bundled = $oldBundled;
Gateway::$bundledEnabled = $oldBundledEnabled;
Service::$row = $oldService;
Auth::$id = $oldAuth;
})();
