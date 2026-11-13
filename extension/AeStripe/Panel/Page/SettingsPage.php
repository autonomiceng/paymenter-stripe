<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Panel/Page/SettingsPage.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Page;
use Filament\Pages\Page;
/** A Settings page that is not a resource page. See Concerns\SettingsType. */
abstract class SettingsPage extends Page implements PageType
{
    use Concerns\SettingsType;
    protected const WIDE = false;
    protected const PAGE_CLASSES = ['domains-page-settings'];
}
