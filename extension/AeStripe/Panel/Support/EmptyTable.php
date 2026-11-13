<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/EmptyTable.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Support;
use Filament\Tables\Table;
/** A staff table carries data-piece="table"; its empty state draws inside it. An empty staff table says what fills it. A search or filter that hid the rows says so, and how to get them back. */
final class EmptyTable
{
    public const CLEAR='Clear the search or filters to see all rows.';
    /** @param ?\Closure():?string $blocked a heading that takes over with no step, such as rows that can't be read */
    public static function apply(Table $table, string $empty, string $next, string $noMatch, ?\Closure $blocked=null): Table
    {
        $hidden=fn($livewire)=>filled($livewire->getTableSearch())||$livewire->getTable()->isFiltered();
        $blocked??=fn()=>null;
        return $table->extraAttributes(['data-piece'=>'table'],merge:true)->emptyStateHeading(fn($livewire)=>$blocked()??($hidden($livewire)?$noMatch:$empty))
            ->emptyStateDescription(fn($livewire)=>$blocked()!==null?null:($hidden($livewire)?self::CLEAR:$next));
    }
}
