<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Presentation/Entry.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation;
/** The dictionary entry every State and Plain case carries: the nearest existing words, why none of them fits, and who
 * decided it and when. The coverage test fails for a case without one, so a new word cannot arrive without its reason. */
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT)]
final class Entry
{
    /** @param list<State|Plain> $nearest */
    public function __construct(public readonly array $nearest, public readonly string $why, public readonly string $decided) {}
    public static function of(State|Plain $case): ?self
    {
        $found = (new \ReflectionEnumUnitCase($case::class, $case->name))->getAttributes(self::class);
        return count($found) === 1 ? $found[0]->newInstance() : null;
    }
}
