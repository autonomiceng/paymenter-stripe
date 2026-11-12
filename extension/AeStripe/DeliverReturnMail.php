<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Gateways\AeStripe;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Duplicate queue jobs share one retained recipient entry and cannot repeat its completed delivery. */
final class DeliverReturnMail implements ShouldQueue
{
    public function __construct(public int $outboxId) {}

    public function handle(): void
    {
        (new AeStripe)->deliverReturnMail($this->outboxId);
    }
}
