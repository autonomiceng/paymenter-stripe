<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation;
use Filament\Support\Contracts\HasLabel;
/** The closed list of status words AE Stripe may show, owned here and never synced. It is the block every repo shares
 * with Domains, with Domains' words and tones, plus Awaiting payment for an invoice a customer still has to pay. A page
 * passes a case and never types the word, colour or mark. Only the design system owner adds a case. HasLabel only: a
 * State is never a Filament colour. */
enum State: string implements HasLabel
{
    #[Entry([State::Failed], 'A check or result that passed.', 'Shared block. DESIGN.md Panels, Nov 12, 2026.')]
    case Ok = 'ok';

    #[Entry([State::Checking, State::CouldntRead], 'A value never read, or waiting its turn, with nothing for anyone to do.', 'Shared block. Ilya, Nov 12, 2026, decision 4.')]
    case NotReadYet = 'not_read_yet';
    #[Entry([State::NotSupported], 'Something we don\'t offer by choice.', 'Shared block. Ilya, Nov 12, 2026, decision 2.')]
    case NotForSale = 'not_for_sale';
    #[Entry([State::NotForSale], 'Something Stripe or this server can\'t do at all.', 'Shared block. Ilya, Nov 12, 2026, decisions 2 and 3.')]
    case NotSupported = 'not_supported';
    #[Entry([State::NotReadYet, State::CouldntRead], 'Stripe sent a status we have not mapped, and the code shows under it.', 'Shared block. DESIGN.md Panels, Nov 12, 2026.')]
    case NotRecognized = 'not_recognized';

    #[Entry([State::NotReadYet, State::AwaitingPayment], 'A read or check waiting on Stripe now.', 'Shared block. Ilya, Nov 12, 2026, M0 rounds 4 and 5.')]
    case Checking = 'checking';
    #[Entry([State::Checking, State::NotSet], 'Waiting on an invoice that is not due yet. Cyan for staff and amber for customers, who must pay.', 'Domains\' word. Ilya, Nov 12, 2026, plan r2 decision 4.')]
    case AwaitingPayment = 'awaiting_payment';

    #[Entry([State::NotReadYet], 'A setting staff must choose is empty, such as a Stripe key.', 'Shared block. Replaces Missing on settings. Ilya, Nov 12, 2026, decision 4.')]
    case NotSet = 'not_set';

    #[Entry([State::Failed, State::NotReadYet], 'The last read of a value failed.', 'Shared block. Ilya, Nov 12, 2026, decision 4.')]
    case CouldntRead = 'couldnt_read';
    #[Entry([State::CouldntRead], 'A payment, change or check that broke. The reason says what.', 'Shared block. DESIGN.md Panels, Nov 12, 2026.')]
    case Failed = 'failed';

    /** The block every repo shares with Domains: results and the six availability states. */
    public const SHARED = [self::Ok, self::Failed, self::NotReadYet, self::Checking, self::CouldntRead, self::NotSet, self::NotForSale, self::NotSupported, self::NotRecognized];

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK', self::NotReadYet => 'Not read yet', self::NotForSale => 'Not for sale', self::NotSupported => 'Not supported',
            self::NotRecognized => 'Not recognized', self::Checking => 'Checking', self::AwaitingPayment => 'Awaiting payment',
            self::NotSet => 'Not set', self::CouldntRead => 'Couldn\'t read', self::Failed => 'Failed',
        };
    }
    public function getLabel(): string { return $this->label(); }
    /** Amber means this reader must act and cyan means someone else is on it. Only Awaiting payment differs by reader. */
    public function tone(Reader $reader): Tone
    {
        return match ($this) {
            self::AwaitingPayment => $reader === Reader::Staff ? Tone::Progress : Tone::Attention,
            self::Ok => Tone::Ok,
            self::NotReadYet, self::NotForSale, self::NotSupported, self::NotRecognized => Tone::Off,
            self::Checking => Tone::Progress,
            self::NotSet => Tone::Attention,
            self::CouldntRead, self::Failed => Tone::Failed,
        };
    }
}
