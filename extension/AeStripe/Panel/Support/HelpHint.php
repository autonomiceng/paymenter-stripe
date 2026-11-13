<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/HelpHint.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Support;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
/** The one "?" on staff pages: blue and next to its label. Filament's hintIcon has no focus target; this button shows its tooltip on hover and on keyboard focus. */
final class HelpHint
{
    /** A dialog focuses its first button when it opens, which would open this tooltip with nothing hovered. Focus coming from outside the dialog goes on to its first real field; Tab and hover inside it still show help. */
    private const PASS_DIALOG_FOCUS=<<<'JS'
const dialog=$el.closest('.fi-modal-window');if(dialog&&!dialog.contains($event.relatedTarget)){const field=[...dialog.querySelectorAll('input:not([type=hidden]),select,textarea,button')].find(e=>!e.disabled&&e.offsetParent&&!e.closest('.fi-icon-btn'));if(field){$event.stopImmediatePropagation();field.focus()}}
JS;
    public static function make(string $text, string $label = 'More help'): Action
    {
        // No click handler: a click must not mount a nested action over an open dialog.
        // Filament pushes hints to the far end of the label row; a skin keeps domains-help beside the label.
        return Action::make('help')->label($label)->icon('heroicon-m-question-mark-circle')->iconButton()->color('primary')->tooltip($text)->livewireClickHandlerEnabled(false)
            ->extraAttributes(['class' => 'domains-help','x-on:focus.capture' => self::PASS_DIALOG_FOCUS]);
    }
    /** Text with the same "?" beside it, for page HTML outside a field or entry. */
    public static function beside(string $html, string $text, string $label = 'More help'): HtmlString
    { return new HtmlString('<span class="domains-help-beside">'.$html.self::make($text, $label)->toHtml().'</span>'); }
}
