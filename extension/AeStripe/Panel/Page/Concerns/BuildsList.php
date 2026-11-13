<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/Concerns/BuildsList.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page\Concerns;
use Filament\Actions\{BulkAction, BulkActionGroup};
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Support\{EmptyTable, Sheet, TableColumns};
use Paymenter\Extensions\Gateways\AeStripe\Panel\Page\{Column, Filter, Section};
/** What both list types share: the head, any notices, then the standard table. The page supplies notices, columns,
 * filters, the empty line and its bulk actions as data; search, sort, paging, the empty state, the column menu and the row link belong to
 * the type. */
trait BuildsList
{
    use RendersType;
    use InteractsWithTable;
    /** @return list<Column> */
    abstract protected function columns(): array;
    /** @return list<Filter> */
    protected function filters(): array { return []; }
    /** What fills the list when it is empty and the next step, then what a search or filter that hid every row says:
     * ['No monitored domains', 'Turn on monitoring on a hosting product.', 'No monitored domains match']. Without the
     * third, the list type's own line shows. @return array{0:string,1:string,2?:string} */
    abstract protected function emptyLine(): array;
    /** Native bulk actions on the rows staff tick. Each checks its own permission when it runs. @return list<BulkAction> */
    protected function bulkActions(): array { return []; }
    /** The search box's words, when the list is searchable. */
    protected function searchLabel(): ?string { return null; }
    /** Above the table, only while something needs saying: each section opens with a notice, or holds the steps it
     * asks for as a trace. @return list<Section> */
    protected function notices(): array { return []; }
    final public function content(Schema $schema): Schema
    {
        $notices = self::sectionsOnly($this->notices(), 'Above a list');
        foreach ($notices as $section) {
            $first = $section->rows[0] ?? null;
            if (!$section->hasTrace() && !($first instanceof \Paymenter\Extensions\Gateways\AeStripe\Panel\Row && Section::isNotice($first))) { throw new \InvalidArgumentException('A section above a list opens with a notice or holds steps.'); }
        }
        return $schema->components([...($notices === [] ? [] : [Sheet::single(array_map(static fn (Section $s) => $s->toSchema(), $notices), 'list-notices')]), EmbeddedTable::make()]);
    }
    /** @return array{0:list<Column>,1:list<Filter>} */
    private function listParts(): array
    {
        $columns = array_values($this->columns());$filters = array_values($this->filters());
        foreach ($columns as $c) { if (!$c instanceof Column) { throw new \InvalidArgumentException('A list column is a Column.'); } }
        foreach ($filters as $f) { if (!$f instanceof Filter) { throw new \InvalidArgumentException('A list filter is a Filter.'); } }
        if ($columns === []) { throw new \InvalidArgumentException('A list has at least one column.'); }
        return [$columns, $filters];
    }
    /** @param list<Column> $columns @param list<Filter> $filters */
    private function standardTable(Table $table, array $columns, array $filters, string $noMatch): Table
    {
        [$empty, $next] = $line = $this->emptyLine();$noMatch = $line[2] ?? $noMatch;
        $bulk = array_values($this->bulkActions());
        foreach ($bulk as $action) { if (!$action instanceof BulkAction) { throw new \InvalidArgumentException('A bulk action is a native Filament bulk action.'); } }
        $built = array_map(static fn (Column $c) => $c->toColumn(), $columns);
        $hidden = array_values(array_map(static fn (Column $c): string => $c->name, array_filter($columns, static fn (Column $c): bool => $c->isOptional())));
        $table = TableColumns::choose(EmptyTable::apply($table, $empty, $next, $noMatch), $built, $hidden)
            ->filters(array_map(static fn (Filter $f) => $f->toFilter(), $filters))
            ->paginated([25, 50, 100])->defaultPaginationPageOption(25);
        if ($bulk !== []) { $table->toolbarActions([BulkActionGroup::make($bulk)->label('Selected')]); }
        $label = $this->searchLabel();
        return $label === null ? $table : $table->searchPlaceholder($label);
    }
}
