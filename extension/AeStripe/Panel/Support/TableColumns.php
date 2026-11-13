<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/TableColumns.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Support;
use Filament\Actions\Action;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
/**
 * An owned table with more than 4 columns gets a Columns button. The first column names the row, so it always stays.
 * Defaults must be right on their own: the choice lasts one session.
 */
final class TableColumns
{
    /** @param list<Column> $columns @param list<string> $hidden columns that start hidden */
    public static function choose(Table $table, array $columns, array $hidden=[]): Table
    {
        if (count($columns)>4) {
            foreach (array_values($columns) as $i=>$column) {
                $i===0?$column->toggleable(false):$column->toggleable(isToggledHiddenByDefault:in_array($column->getName(),$hidden,true));
            }
            $table->columnManagerTriggerAction(fn(Action $action)=>$action->label('Columns')->button());
        }
        return $table->columns($columns);
    }
}
