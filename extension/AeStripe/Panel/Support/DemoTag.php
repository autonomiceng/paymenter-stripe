<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/DemoTag.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Support;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\TextColumn;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Services\HookRegistration;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Support\DemoData;
/** Staff Demo tag and banner. A helper, not a macro: macros are global changes to Filament classes. */
final class DemoTag
{
    /** Adds the Demo tag after the value when the row's record is demo data. */
    public static function apply(TextEntry|TextColumn $component): TextEntry|TextColumn
    {
        return $component->suffix(static fn (mixed $record): ?HtmlString => DemoData::is($record) ? DemoData::tag(inline: true) : null);
    }
    /** One line at the top of Domains staff pages while seeded demo accounts exist. */
    public static function banner(): string
    {
        if (!DemoData::present()) { return ''; }
        return '<div role="note" class="domains-demo-banner">'
            .e(DemoData::BANNER).'</div>';
    }
    public static function boot(): void
    {
        if (!defined(PanelsRenderHook::class.'::CONTENT_START') || !HookRegistration::claim(FilamentView::getFacadeRoot(), self::class)) { return; }
        FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_START, static function (): string {
            $page = \Livewire\Livewire::current();
            return $page && str_starts_with($page::class, 'Paymenter\\Extensions\\Servers\\Domains\\Admin\\') ? self::banner() : '';
        });
    }
}
