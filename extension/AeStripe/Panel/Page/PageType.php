<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/PageType.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page;
/** Every staff page is one of four types: Detail, Settings, List or History. A page extends its type (or the type's
 * record or resource form) and supplies data; the type owns the head, the frame, section order and placement.
 * A page with no mock of its own takes its type's mock. */
interface PageType
{
    /** The type's classes. A page extends exactly one. */
    public const TYPES = [DetailPage::class, RecordDetailPage::class, SettingsPage::class, ResourceSettingsPage::class, ListPage::class, QueryListPage::class, RecordListPage::class, HistoryPage::class, RecordHistoryPage::class];
}
