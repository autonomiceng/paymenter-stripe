<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Row.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel;
use Filament\Actions\Action;
use Filament\Schemas\Components\{Actions, Component};
use Filament\Support\Components\Contracts\HasEmbeddedView;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Support\HelpHint;
/** One sheet row: a label with an optional "?", one Value, and at most two native actions in the action column.
 * The page registers its actions as it always has (keys, modals, authorisation and refresh hooks are its own); the
 * row only places them. A row whose value or live state is Checking gets the waiting edge, so a page can't paint one.
 * Emits neutral domains-panel-row markup with plain Filament actions, so it reads as a stacked line without a skin.
 * Row::notice() is the page notice: the same row shape with a state and sentence instead of a label. */
class Row extends Component implements HasEmbeddedView
{
    public const MAX_ACTIONS = 2;
    protected string $kind = 'fact';
    protected ?string $help = null;
    protected ?Value $live = null;
    protected ?Value $result = null;
    protected ?string $blocked = null;
    protected ?Action $fix = null;
    protected ?Row $blockedBy = null;
    /** @var list<Action> */
    protected array $controls = [];
    private bool $painting = false;

    final public function __construct(protected string $rowLabel, protected Value $rowValue) {}
    public static function make(string $label, Value $value): static
    {
        if (trim($label) === '') { throw new \InvalidArgumentException('A row needs its label.'); }
        $row = app(static::class, ['rowLabel' => $label, 'rowValue' => $value]);
        $row->configure();
        return $row->key('row-'.self::slug($label));
    }
    /** A page notice at the top of its section: the state and its sentence, then at most one action. $key names it, so
     * the rows it blocks can point to it. */
    public static function notice(string $key, Value $state, ?Action $action = null): static
    {
        if ($state->kind !== 'state') { throw new \InvalidArgumentException('A notice starts with a state.'); }
        $row = app(static::class, ['rowLabel' => '', 'rowValue' => $state]);
        $row->configure();
        $row->kind = 'notice';
        $row->key('notice-'.self::slug($key));
        return $action === null ? $row : $row->actions($action);
    }
    /** The "?" after the label's last word: what the row means, at most a few sentences. Never the only copy of a step. */
    public function help(string $text): static { $this->help = trim($text) === '' ? null : $text; return $this; }
    /** The row's native actions, as the page built them. At most two; the rest go in a "..." menu on the page. */
    public function actions(Action ...$actions): static
    {
        if (count($actions) > self::MAX_ACTIONS) { throw new \InvalidArgumentException('A row has at most '.self::MAX_ACTIONS.' actions.'); }
        if ($this->kind === 'notice' && count($actions) > 1) { throw new \InvalidArgumentException('A notice has at most one action.'); }
        $this->controls = array_values($actions);
        $this->holdBlocked();
        // The actions' key follows the row's key, so a row keyed after its actions still names them by its own key.
        return $this->schema($actions === [] ? [] : [Actions::make($this->controls)->key(fn (): string => $this->getKey(false).'-actions')]);
    }
    /** A change running in place: its state takes the action's place until it finishes, and nothing else moves. */
    public function running(Value $state): static { $this->live = self::needsState($state); return $this; }
    /** What the last change did, under the value, such as Failed with what failed. The value itself stays true. */
    public function result(Value $state): static { $this->result = self::needsState($state); return $this; }
    /** Only this row's actions are blocked: $reason says so and how to clear it, under the value, with $fix as its link. */
    public function blocked(string $reason, ?Action $fix = null): static { $this->blocked = $reason; $this->fix = $fix; $this->holdBlocked(); return $this; }
    /** Every change in the section is blocked, and the section's notice says why. */
    public function blockedBy(Row $notice): static
    {
        if ($notice->kind !== 'notice') { throw new \InvalidArgumentException('A row is blocked by a notice.'); }
        $this->blockedBy = $notice;
        $this->holdBlocked();
        return $this;
    }
    public function waits(): bool { return $this->rowValue->waits() || $this->live?->waits() === true; }
    /** The row's id on the page, from its full key, so rows with the same label in other sections stay apart. */
    public function anchor(): string { return 'domains-panel-'.trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->getKey() ?? (string) $this->getKey(false)), '-'); }

    /** The actions this reader can use now. */
    protected function shownControls(): array
    { return $this->live !== null ? [] : array_values(array_filter($this->controls, static fn (Action $a): bool => $a->isVisible())); }
    /** The "?" text. A setting row adds who sets it when this reader has no control. */
    protected function tip(): ?string { return $this->help; }

    /** A blocked action runs nothing even when a request names it, since Filament won't mount or call a disabled action.
     * It reads as enabled only while this row paints it, so its button stays focusable. This replaces any disabled
     * condition the page set on that action. */
    private function holdBlocked(): void
    {
        if ($this->blocked === null && $this->blockedBy === null) { return; }
        foreach ($this->controls as $action) { $action->disabled(fn (): bool => !$this->painting); }
    }

    public function toEmbeddedHtml(): string
    {
        $this->painting = true;
        try { return $this->paint(); } finally { $this->painting = false; }
    }
    private function paint(): string
    {
        $shown = $this->shownControls();
        $why = $this->blocked !== null ? $this->anchor().'-why' : $this->blockedBy?->anchor();
        if ($why !== null) {
            foreach ($shown as $action) {
                // Visible, focusable and named, but it runs nothing until the cause is cleared: no link, Alpine or Livewire handler.
                $action->url(null)->alpineClickHandler('$event.preventDefault()')->livewireClickHandlerEnabled(false)->extraAttributes(['aria-disabled' => 'true', 'aria-describedby' => $why, 'class' => 'domains-panel-blocked'], merge: true);
            }
        }
        $tip = $this->tip();
        return view('gateways.ae-stripe::panel.row', [
            'kind' => $this->kind, 'id' => $this->anchor(), 'label' => $this->rowLabel, 'value' => $this->rowValue,
            'help' => $tip === null ? null : HelpHint::make($tip, 'About '.$this->rowLabel)->toHtml(),
            'live' => $this->live, 'result' => $this->result, 'waits' => $this->waits(),
            'blocked' => $this->blocked, 'fix' => $this->fix?->toHtml(), 'why' => $this->blocked !== null ? $why : null,
            'actions' => $shown === [] ? null : $this->getChildSchema()->toHtml(),
        ])->render();
    }
    private static function needsState(Value $value): Value
    {
        if ($value->kind !== 'state') { throw new \InvalidArgumentException('A live or result line is a state.'); }
        return $value;
    }
    private static function slug(string $text): string { return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-') ?: substr(md5($text), 0, 8); }
}
