<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

/** A temporary save conflict that a webhook must retry and a browser can explain. */
class SavedMethodRetryException extends \RuntimeException
{
}
