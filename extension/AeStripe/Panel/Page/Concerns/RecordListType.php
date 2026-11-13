<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/Concerns/RecordListType.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page\Concerns;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\{Builder, Model};
/** Saved records to pick from: the head, then the standard table (BuildsList) over an Eloquent query. Search, sort,
 * filters and paging run in the database, through Filament's own table query, so a column or filter that is not a
 * plain field passes its query as data. */
trait RecordListType
{
    use BuildsList;
    /** The records, before search, filters, sort and paging. */
    abstract protected function query(): Builder;
    /** The column the list starts sorted by, and its direction. @return ?array{0:string,1:'asc'|'desc'} */
    protected function defaultSort(): ?array { return null; }
    /** Where a row opens. Null opens nothing. */
    protected function rowUrl(Model $record): ?string { return null; }
    final public function table(Table $table): Table
    {
        [$columns, $filters] = $this->listParts();
        $table = $this->standardTable($table->query(fn (): Builder => $this->query()), $columns, $filters, 'No records match.')
            ->recordUrl(fn (Model $record): ?string => $this->rowUrl($record));
        $sort = $this->defaultSort();
        if ($sort !== null) {
            if (!in_array($sort[1] ?? null, ['asc', 'desc'], true)) { throw new \InvalidArgumentException('A list sorts asc or desc.'); }
            $table->defaultSort($sort[0], $sort[1]);
        }
        return $table;
    }
}
