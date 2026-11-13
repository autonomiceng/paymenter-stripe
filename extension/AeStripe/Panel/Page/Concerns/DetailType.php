<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/Concerns/DetailType.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page\Concerns;
use Filament\Schemas\Schema;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Page\Section;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Support\Sheet;
/** A record page: the head, then the sheet of sections, with the rail beside it (under it on a phone). */
trait DetailType
{
    use RendersType;
    /** The sheet, top to bottom. @return list<Section> */
    abstract protected function sections(): array;
    /** Beside the sheet: activity, or a few facts about the account. @return list<Section> */
    protected function rail(): array { return []; }
    final public function content(Schema $schema): Schema
    {
        $sheet = array_map(static fn (Section $s) => $s->toSchema(), self::sectionsOnly($this->sections(), 'The sheet'));
        $rail = array_map(static fn (Section $s) => $s->toSchema(), self::sectionsOnly($this->rail(), 'The rail'));
        return $schema->components([Sheet::split($sheet, $rail, 'sheet')]);
    }
}
