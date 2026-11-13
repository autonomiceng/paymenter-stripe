<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/RecordListPage.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page;
use Filament\Resources\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\{Builder, Model};
use Livewire\Attributes\Url;
/** A resource's list of its saved records, such as monitored domains. It reads the resource's query, opens each row's
 * view page and checks the resource's list permission on every request, as Filament's own list page does.
 * See Concerns\RecordListType. */
abstract class RecordListPage extends Page implements PageType, HasTable
{
    use Concerns\RecordListType;
    protected const WIDE = true;
    protected const PAGE_CLASSES = ['domains-page-list', 'fi-resource-list-records-page'];
    /** The search, sort and filters live in the address, as on Filament's own list page, so a link or a bookmark opens
     * the same rows. @var array<string,mixed>|null */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;
    /** @var ?string */
    #[Url(as: 'search')]
    public $tableSearch = '';
    #[Url(as: 'sort')]
    public ?string $tableSort = null;
    public function mount(): void { $this->authorizeAccess(); }
    public function hydrate(): void { $this->authorizeAccess(); }
    protected function authorizeAccess(): void { abort_unless(static::getResource()::canViewAny(), 403); }
    protected function query(): Builder { return static::getResource()::getEloquentQuery(); }
    protected function rowUrl(Model $record): ?string { return static::getResource()::hasPage('view') ? static::getResource()::getUrl('view', ['record' => $record]) : null; }
}
