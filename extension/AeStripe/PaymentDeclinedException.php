<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

// Stripe declined the entered payment details, so the checkout form clears them for another card.
final class PaymentDeclinedException extends \App\Exceptions\DisplayException {}
