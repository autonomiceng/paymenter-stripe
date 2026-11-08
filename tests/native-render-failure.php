<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Gateways\AeStripe;

// Simulate PCRE's documented null result only at the extension's render boundary.
final class RenderFailure
{
    public static int $hits = 0;
}

function preg_replace_callback(...$arguments)
{
    ++RenderFailure::$hits;
    return null;
}
