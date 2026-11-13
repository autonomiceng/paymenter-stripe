<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/Concerns/RendersType.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page\Concerns;
use Filament\Actions\{Action, ActionGroup};
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Head;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Page\Section;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Support\Sheet;
/** The part every page type shares: the head, the header actions and the frame. All of it is final, so a page can't
 * draw its own head, header, footer, widgets or view; it only answers head(), actions() and poll() with data. */
trait RendersType
{
    /** The title, its tag, and the line under it with the record's own state. */
    abstract protected function head(): Head;
    /** Native Filament actions beside the title. Their modals, authorisation and refresh hooks are their own.
     * @return list<Action|ActionGroup> */
    protected function actions(): array { return []; }
    /** A page that waits on a check names its public refresh method and how often: ['pollCheck', 5]. Seconds are
     * 5 or 60. Null for no refresh. @return ?array{0:string,1:int} */
    protected function poll(): ?array { return null; }

    final public function getTitle(): string|Htmlable { return $this->head()->title; }
    final public function getHeading(): string|Htmlable|null { return $this->head()->heading(); }
    final public function getSubheading(): string|Htmlable|null { return $this->head()->line(); }
    final protected function getHeaderActions(): array
    {
        $actions = array_values($this->actions());
        foreach ($actions as $action) {
            if (!$action instanceof Action && !$action instanceof ActionGroup) { throw new \InvalidArgumentException('A header action is a native Filament action.'); }
        }
        return $actions;
    }
    final public function getHeader(): ?View
    {
        $poll = $this->poll();
        if ($poll !== null && (!is_string($poll[0] ?? null) || !method_exists($this, $poll[0]) || !in_array($poll[1] ?? null, [5, 60], true))) {
            throw new \InvalidArgumentException('A page polls one of its own public methods every 5 or 60 seconds.');
        }
        return view('gateways.ae-stripe::panel.page-head', ['poll' => $poll]);
    }
    final public function getFooter(): ?View { return null; }
    final protected function getHeaderWidgets(): array { return []; }
    final protected function getFooterWidgets(): array { return []; }
    final public function getView(): string { return 'filament-panels::pages.page'; }
    final public function getMaxContentWidth(): Width|string|null { return static::WIDE ? Width::Full : Sheet::WIDTH; }
    /** The type's class on the page, plus Filament's own view or list class so a host skin styles one type one way. */
    final public function getPageClasses(): array { return [...parent::getPageClasses(), ...static::PAGE_CLASSES]; }

    /** @param list<mixed> $sections @return list<Section> */
    private static function sectionsOnly(array $sections, string $where): array
    {
        $sections = array_values($sections);
        foreach ($sections as $section) {
            if (!$section instanceof Section) { throw new \InvalidArgumentException($where.' holds sections only.'); }
        }
        return $sections;
    }
}
