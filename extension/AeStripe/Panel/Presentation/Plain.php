<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Presentation/Plain.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation;
use Filament\Support\Contracts\HasLabel;
/** Fixed factual words with no mark and no tone: a setting's value, a mode, an answer. They are not statuses, so a
 * page can't make On green or Off grey. Same owner rule and Entry as State. */
enum Plain: string implements HasLabel
{
    #[Entry([Plain::On], 'Answers a yes or no question. On is a setting that can be switched.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case Yes = 'yes';
    #[Entry([Plain::Off], 'Answers a yes or no question. Off is a setting that can be switched.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case No = 'no';
    #[Entry([Plain::Yes], 'A setting that is on. Plain text with no mark, so it can\'t read as a status.', 'On stops being a status word (K14). DESIGN.md Panels, Nov 12, 2026.')]
    case On = 'on';
    #[Entry([Plain::No, State::Stopped], 'A setting that is off. Stopped is a status.', 'Off stops being a status word (K14). DESIGN.md Panels, Nov 12, 2026.')]
    case Off = 'off';
    #[Entry([Plain::Test], 'A registrar account\'s mode that spends real money.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case Live = 'live';
    #[Entry([Plain::Live], 'A registrar account\'s mode on the registrar\'s test system.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case Test = 'test';
    #[Entry([Plain::NotApplicable], 'A value this reader can see but not change.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case ReadOnly = 'read_only';
    #[Entry([Plain::Custom, Plain::ServerDefault], 'A setting that follows our shared default.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case Default = 'default';
    #[Entry([Plain::Default], 'A setting changed from the shared default.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case Custom = 'custom';
    #[Entry([Plain::Default], 'How often a scheduled job runs.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case Daily = 'daily';
    #[Entry([Plain::Default], 'A setting that follows the server\'s own setting, not ours.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case ServerDefault = 'server_default';
    #[Entry([Plain::NotApplicable, State::NotReadYet], 'A list that was read and has no items. Not read yet means it was never read.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case None = 'none';
    #[Entry([Plain::None, State::NotSupported], 'A row that doesn\'t apply to this record. Not supported means it could apply but can\'t be done.', 'DESIGN.md Panels, Records and jobs, Nov 12, 2026.')]
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Yes', self::No => 'No', self::On => 'On', self::Off => 'Off', self::Live => 'Live', self::Test => 'Test', self::ReadOnly => 'Read-only',
            self::Default => 'Default', self::Custom => 'Custom', self::Daily => 'Daily', self::ServerDefault => 'Server default', self::None => 'None', self::NotApplicable => 'Not applicable',
        };
    }
    public function getLabel(): string { return $this->label(); }
}
