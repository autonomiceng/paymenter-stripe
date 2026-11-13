<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Presentation/Fact.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation;
use Illuminate\Database\Eloquent\Model;
/** Data a page shows as a value: a model attribute, a date, money, a count, a list of names, a host name or a provider
 * code. No constructor takes free text, so a status word can't arrive as a fact. A constructor that reads data returns
 * null when there is nothing to show; the page then takes a state from Availability instead of printing a blank. */
final class Fact
{
    /** How the value reads: text, code and host are names (host and code in mono), money and count are numbers. */
    public const KINDS = ['text', 'code', 'host', 'money', 'count', 'date'];
    private function __construct(public readonly string $kind, public readonly string $text, public readonly ?string $datetime = null, public readonly ?string $title = null, public readonly bool $instant = false)
    { assert($kind === 'code' || !self::isWord($text), 'A fact never reads as a state or plain word: '.$text); }

    /** A model attribute. Status and state columns are refused: their word comes from State. */
    public static function attr(Model $model, string $column): ?self
    {
        if (preg_match('/(?:^|_)(?:status|state)$/D', $column) === 1) { throw new \InvalidArgumentException('A status column takes a State, not a fact: '.$column); }
        $value = $model->getAttribute($column);
        if ($value instanceof \BackedEnum) { $value = $value->value; }
        if (is_bool($value) || $value instanceof \DateTimeInterface || !is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Use Plain for a yes or no and Fact::date for a time: '.$column);
        }
        $text = trim((string) $value);
        return $text === '' ? null : new self('text', $text);
    }
    public static function count(int $count): self { return new self('count', number_format($count)); }
    /** "$1,240.00" from a USD decimal string. Never rounds: a cost with more places keeps them ("$12.3456"). */
    public static function money(?string $decimal): ?self
    {
        if ($decimal === null || preg_match('/^(-?)(\d{1,18})(?:\.(\d{1,8}))?$/D', $decimal, $m) !== 1) { return null; }
        $places = str_pad(rtrim($m[3] ?? '', '0'), 2, '0');
        return new self('money', $m[1].'$'.number_format((int) $m[2]).'.'.$places);
    }
    /** Host lists keep host metadata. At most three names, then a count: "Enom TEST, NameSilo and 4 more". Callers order them, most used first. @param list<string> $names */
    public static function names(array $names): ?self
    {
        $names = array_values(array_filter(array_map(static fn (string $name): string => trim(preg_replace('/\s+/u', ' ', $name)), $names), static fn (string $name): bool => $name !== ''));
        if ($names === []) { return null; }
        $kind = count(array_filter($names, static fn (string $name): bool => self::host($name) !== null)) === count($names) ? 'host' : 'text';
        return new self($kind, implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' and '.(count($names) - 3).' more' : ''));
    }
    /** TLDs, popular ones first: ".com, .net, .org and 596 more". @param list<string> $tlds without the dot */
    public static function tlds(array $tlds): ?self { return $tlds === [] ? null : new self('text', TldNames::summary($tlds)); }
    /** A domain or nameserver name, in mono. Null for anything that isn't a host name. */
    public static function host(?string $name): ?self
    {
        $name = strtolower(rtrim(trim((string) $name), '.'));
        return strlen($name) <= 253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/D', $name) === 1 ? new self('host', $name) : null;
    }
    /** A provider's own code, in mono, so staff can report it. Null for anything that doesn't look like a code, so provider text never echoes.
     * A code is opaque, so it may be spelled like one of our words ("failed"); it shows as the code under Not recognized, never as the word. */
    public static function code(?string $code): ?self
    { return $code !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,63}$/D', $code) === 1 ? new self('code', $code) : null; }
    /** A moment. Staff read date and time in their browser's zone (the moment partial), with UTC on hover and the site's
     * staff zone, named, as the text without script. Customers read the date only, a calendar day. */
    public static function date(int|\DateTimeInterface $at, Reader $reader): self
    {
        $at = $at instanceof \DateTimeInterface ? $at->getTimestamp() : $at;
        if ($reader === Reader::Customer) { $day = StaffTime::local($at); return new self('date', $day->format('M j, Y'), $day->format('Y-m-d')); }
        $utc = new \DateTimeImmutable('@'.$at);
        return new self('date', StaffTime::at($at), $utc->format('Y-m-d\TH:i:s\Z'), $utc->format('M j, Y, g:i A').' UTC', true);
    }
    /** A calendar date such as an expiry, the same for every reader, with no zone change. Null when it isn't a date. */
    public static function day(string|\DateTimeInterface|null $date): ?self
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $day !== false && $day->format('Y-m-d') === $date ? new self('date', $day->format('M j, Y'), $date) : null;
    }
    /** Days around an expiry, as the reminder schedule reads: "60, 30, 7 and 1 days before, 3 days after". Null for no days.
     * @param list<int> $before newest first @param list<int> $after */
    public static function days(array $before, array $after): ?self
    {
        $list = static fn (array $days): string => count($days) > 1 ? implode(', ', array_slice($days, 0, -1)).' and '.end($days) : (string) $days[0];
        $part = static fn (array $days, string $when): ?string => $days === [] ? null : $list($days).' '.($days === [1] ? 'day' : 'days').' '.$when;
        $text = implode(', ', array_filter([$part(array_map('intval', $before), 'before'), $part(array_map('intval', $after), 'after')]));
        return $text === '' ? null : new self('text', $text);
    }
    /** True when $text is a State or Plain word, so computed text can't pass for one. */
    public static function isWord(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        foreach ([...State::cases(), ...Plain::cases()] as $case) { if (mb_strtolower($case->label()) === $text) { return true; } }
        return false;
    }
}
