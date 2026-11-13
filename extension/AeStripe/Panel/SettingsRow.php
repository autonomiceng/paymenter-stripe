<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/SettingsRow.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel;
use Filament\Actions\Action;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation\Plain;
/** A setting: the same row as Row, for values someone chooses. It always knows who sets it, so a reader with no
 * control here (no permission, or someone else's setting) sees the plain value and a "?" that says who sets it.
 * A switch is the page's own native action drawn as a toggle; the row's value says On or Off from the same bool, so
 * the word and the switch can't disagree. */
final class SettingsRow extends Row
{
    protected string $kind = 'setting';
    private ?string $setBy = null;
    private ?bool $on = null;

    /** $setBy is one sentence: "Set by the customer on their payment page." */
    public static function setting(string $label, Value $value, string $setBy): self
    {
        $row = self::make($label, $value);
        $row->setBy = self::sentence($setBy);
        return $row;
    }
    /** An on or off setting that takes effect in place. $switch is the page's action that flips it. */
    public static function toggle(string $label, bool $on, Action $switch, string $setBy, ?string $sub = null): self
    {
        $row = self::setting($label, Value::plain($on ? Plain::On : Plain::Off, $sub), $setBy);
        $row->on = $on;
        return $row->actions($switch);
    }
    public function actions(Action ...$actions): static
    {
        if ($this->on !== null && count($actions) !== 1) { throw new \InvalidArgumentException('A switch row has one control: its switch.'); }
        return parent::actions(...$actions);
    }
    /** The one control a switch row may have after its switch, such as a link that changes what the switch uses. */
    public function also(Action $action): self
    {
        if ($this->on === null || ($switch = $this->controls[0] ?? null) === null) { throw new \LogicException('Only a switch row takes a control after its switch.'); }
        parent::actions($switch, $action);
        return $this;
    }
    protected function tip(): ?string
    {
        if ($this->shownControls() !== []) { return $this->help; }
        return trim($this->setBy.' '.($this->help ?? ''));
    }
    public function toEmbeddedHtml(): string
    {
        if ($this->setBy === null) { throw new \LogicException('A setting says who sets it: use SettingsRow::setting().'); }
        if ($this->on !== null && ($switch = $this->controls[0] ?? null) !== null) {
            $why = $this->blocked !== null ? $this->anchor().'-why' : $this->blockedBy?->anchor();
            $switch->view('gateways.ae-stripe::panel.switch', ['on' => $this->on, 'name' => $this->rowLabel, 'why' => $why]);
        }
        return parent::toEmbeddedHtml();
    }
    private static function sentence(string $text): string
    {
        $text = trim($text);
        if ($text === '' || !str_ends_with($text, '.')) { throw new \InvalidArgumentException('Who sets a setting is one sentence.'); }
        return $text;
    }
}
