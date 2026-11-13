<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Head.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation\{Fact, Reader, State};
/** A page head: the title with its tag, then one line with the record's own state, a few facts and one link. It only
 * renders what it is given: no queries, and a job never takes the head (a job gets its own row). Header actions stay
 * native Filament actions on the page, so their modals, authorisation and refresh hooks are untouched.
 * A page returns heading() from getHeading() and line() from getSubheading(). */
final class Head
{
    private bool $domain = false;
    private bool $demo = false;
    private bool $test = false;
    private ?State $state = null;
    private ?string $record = null;
    /** @var list<array{label:string,fact:Fact}> */
    private array $facts = [];
    private ?array $link = null;
    private function __construct(public readonly string $title, public readonly Reader $reader) {}
    public static function make(string $title, Reader $reader): self { return new self($title, $reader); }
    /** The title is a domain name: it wraps after dots and hyphens. */
    public function domain(): self { $this->domain = true; return $this; }
    /** Demo for seeded records, Test for a registrar test-mode record. */
    public function tag(bool $demo, bool $test): self { $this->demo = $demo; $this->test = $test; return $this; }
    /** The record's own state. $record names the record, so a skin sweeps the mark only when this record's state changes. */
    public function state(State $state, string $record): self { $this->state = $state; $this->record = $record; return $this; }
    /** A fact on the line, after its short label: "Expires Mar 4, 2027". */
    public function fact(string $label, Fact $fact): self { $this->facts[] = ['label' => $label, 'fact' => $fact]; return $this; }
    /** The one link on the line, such as the registrar account. */
    public function link(string $label, string $url): self { $this->link = ['label' => $label, 'url' => $url]; return $this; }
    public function heading(): Htmlable
    { return new HtmlString(view('gateways.ae-stripe::panel.head-title', ['title' => $this->title, 'domain' => $this->domain, 'demo' => $this->demo, 'test' => $this->test])->render()); }
    /** Null when there is nothing to say under the title. */
    public function line(): ?Htmlable
    {
        if ($this->state === null && $this->facts === [] && $this->link === null) { return null; }
        return new HtmlString(view('gateways.ae-stripe::panel.head-line', ['tone' => $this->state?->tone($this->reader), 'word' => $this->state?->label(),
            'record' => $this->record, 'facts' => $this->facts, 'link' => $this->link])->render());
    }
}
