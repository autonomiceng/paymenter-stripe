<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/Filter.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\{BaseFilter, Filter as FilamentFilter, SelectFilter};
use Illuminate\Database\Eloquent\Builder;
/** One list filter, as data: pick one or more values, a yes-or-no switch, or a typed word. On an array list a pick keeps
 * rows whose column equals it. On a saved-record list $query narrows the query, and the indicator names the filter in
 * use. The list type builds the Filament filter. */
final class Filter
{
    private ?\Closure $query = null;
    private ?\Closure $indicator = null;
    private bool $multiple = false;
    /** @param array<string,string> $options value => label */
    private function __construct(public readonly string $name, public readonly string $label, public readonly array $options, public readonly string $kind) {}
    /** @param array<string,string> $options value => label */
    public static function select(string $name, string $label, array $options): self { return new self($name, $label, $options, 'select'); }
    /** A switch, such as Needs attention. Saved-record lists only. @param \Closure(Builder):Builder $query */
    public static function toggle(string $name, string $label, \Closure $query): self { $f = new self($name, $label, [], 'toggle'); $f->query = $query; return $f; }
    /** A typed word, such as a customer's name. Saved-record lists only. @param \Closure(Builder,?string):Builder $query */
    public static function text(string $name, string $label, \Closure $query): self { $f = new self($name, $label, [], 'text'); $f->query = $query; return $f; }
    /** @param \Closure(Builder,array):Builder $query gets the filter's data: ['value'=>..] or, when multiple, ['values'=>[..]] */
    public function query(\Closure $query): self { $this->query = $query; return $this; }
    public function multiple(): self { $this->multiple = true; return $this; }
    /** @param \Closure(array):?string $indicator what the active filter says above the table */
    public function indicator(\Closure $indicator): self { $this->indicator = $indicator; return $this; }
    public function queries(): bool { return $this->query !== null || $this->kind !== 'select'; }
    /** Built by the list type only. */
    public function toFilter(): BaseFilter
    {
        $filter = match ($this->kind) {
            'select' => SelectFilter::make($this->name)->label($this->label)->options($this->options)->multiple($this->multiple),
            'toggle' => FilamentFilter::make($this->name)->label($this->label)->toggle(),
            default => FilamentFilter::make($this->name)->label($this->label)->schema([TextInput::make($this->name)->label($this->label)])
                ->indicateUsing(fn (array $data): ?string => trim((string) ($data[$this->name] ?? '')) !== '' ? $this->label.': '.trim((string) $data[$this->name]) : null),
        };
        if ($this->query !== null) {
            // Filament passes a query closure its arguments by name, so the page's closure is called with them in order.
            $page = $this->query;
            $filter->query(match ($this->kind) {
                'toggle' => static fn (Builder $query): Builder => $page($query),
                'text' => fn (Builder $query, array $data): Builder => $page($query, $data[$this->name] ?? null),
                default => static fn (Builder $query, array $data): Builder => $page($query, $data),
            });
        }
        if ($this->indicator !== null) { $indicator = $this->indicator; $filter->indicateUsing(static fn (array $data): ?string => $indicator($data)); }
        return $filter;
    }
}
