<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/QueryListPage.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
/** A List page over saved records that is not a resource page. See Concerns\RecordListType. */
abstract class QueryListPage extends Page implements PageType, HasTable
{
    use Concerns\RecordListType;
    protected const WIDE = true;
    protected const PAGE_CLASSES = ['domains-page-list', 'fi-resource-list-records-page'];
}
