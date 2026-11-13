<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Presentation/Read.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation;
/** What we know about reading a value from the registrar: when it last read well, when it last failed, and whether this
 * page is waiting on the registrar for it now (a read or a change). Availability picks the state from this, never from
 * an empty value alone. Times are Unix seconds. */
final class Read
{
    public function __construct(public readonly ?int $lastGoodAt, public readonly ?int $lastFailedAt = null, public readonly bool $checking = false) {}
}
