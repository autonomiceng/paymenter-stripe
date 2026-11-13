<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/Concerns/ListType.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page\Concerns;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Page\{Column, Filter};
/** Records to pick from, read by the page as array rows: the head, then the standard table (BuildsList). Search,
 * sort, filters and paging run over the rows. A list with bulk actions gives each row a `key`, so a ticked row stays
 * the same row after a search or a sort. */
trait ListType
{
    use BuildsList;
    /** Every row as an array of its column values, plus `url` when the row opens a record and `key` when it can be
     * ticked. @return list<array<string,mixed>> */
    abstract protected function rows(): array;
    final public function table(Table $table): Table
    {
        [$columns, $filters] = $this->listParts();
        foreach ([...$columns, ...$filters] as $part) { if ($part->queries()) { throw new \InvalidArgumentException('An array list filters and sorts its rows; a query belongs to a saved-record list.'); } }
        $searchable = array_values(array_map(static fn (Column $c): string => $c->name, array_filter($columns, static fn (Column $c): bool => $c->searchable)));
        $table = $this->standardTable($table, $columns, $filters, 'No rows match.')
            ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, array $filters, int $page, int $recordsPerPage) use ($searchable): LengthAwarePaginator {
                $rows = collect($this->rows())->keyBy(static fn (array $r, int $i): string => (string) ($r['key'] ?? $i))
                    ->filter(static fn (array $r): bool => blank($search) || Str::contains(implode(' ', array_map(static fn (string $k): string => (string) ($r[$k] ?? ''), $searchable)), $search, true))
                    ->filter(static function (array $r) use ($filters): bool {
                        foreach ($filters as $name => $state) { if (filled($state['value'] ?? null) && (string) ($r[$name] ?? '') !== (string) $state['value']) { return false; } }
                        return true;
                    });
                if ($sortColumn !== null) { $rows = $rows->sortBy(static fn (array $r): string => (string) ($r[$sortColumn] ?? ''), SORT_NATURAL | SORT_FLAG_CASE, $sortDirection === 'desc'); }
                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
            })
            ->recordUrl(static fn (array $record): ?string => $record['url'] ?? null);
        return $searchable === [] ? $table : $table->searchable();
    }
}
