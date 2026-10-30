<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

// Core cron treats every exception as a failed payment email. These outcomes need no such email.
final class PaymentPendingException extends \App\Exceptions\DisplayException {}
