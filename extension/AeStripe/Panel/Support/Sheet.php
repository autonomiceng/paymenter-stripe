<?php
// Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/Admin/Support/Sheet.php by tools/panel_sync.py. Change it in Domains and run the script again.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Gateways\AeStripe\Panel\Support;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\{Actions,Component,Flex,Group,Html,Section};
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation\{Missing, StaffTime};
/** The one detail page layout: a sheet of sections with a rail beside it. A row is a label, a value and an action column.
 * It emits plain Filament plus domains-* class names, and data-piece on each piece's root so a walk can crop it. A skin draws the sheet from those; without one it stays stacked Filament sections. */
final class Sheet
{
    /** Detail pages stop here. Tables keep the full width. */
    public const WIDTH=Width::SixExtraLarge;
    /** A rail stops at this many steps. A tab holds the rest. */
    public const RAIL_STEPS=7;
    /** @param list<Component> $sheet @param list<Component> $rail The rail drops under the sheet on narrow screens. */
    public static function split(array $sheet,array $rail,?string $key=null): Group
    {
        return Group::make([
            Group::make($sheet)->key($key)->columnSpan(['default'=>1,'xl'=>2])->extraAttributes(['class'=>'domains-sheet','data-piece'=>'sheet']),
            Group::make($rail)->columnSpan(1)->extraAttributes(['class'=>'domains-rail','data-piece'=>'rail']),
        ])->columns(['default'=>1,'xl'=>3])->columnSpanFull()->extraAttributes(['class'=>'domains-split','data-piece'=>'split']);
    }
    /** A sheet with nothing beside it: one column, so no empty rail space. @param list<Component> $sheet */
    public static function single(array $sheet,?string $key=null): Group
    {
        return Group::make([Group::make($sheet)->key($key)->extraAttributes(['class'=>'domains-sheet','data-piece'=>'sheet'])])
            ->columnSpanFull()->extraAttributes(['class'=>'domains-split domains-split-single','data-piece'=>'split']);
    }
    /** @param list<Component> $rows @param list<Action|Component> $after Beside the title. */
    public static function section(string $title,array $rows,string|Htmlable|null $note=null,?string $key=null,array $after=[]): Section
    {
        $section=Section::make($title)->schema($rows)->description($note)->extraAttributes(['class'=>'domains-sec','data-piece'=>'section']);
        if($key!==null){$section->key($key);}
        return $after?$section->afterHeader($after):$section;
    }
    /** A value row. $side is its action column: actions, or one component that shows the state. */
    public static function row(TextEntry $entry,array|Component $side=[],bool $pending=false,?string $key=null): Flex
    {
        $side=is_array($side)?array_values(array_filter($side)):$side;
        return Flex::make($side===[]?[$entry->inlineLabel()]:[$entry->inlineLabel(),$side instanceof Component?$side->grow(false):Actions::make($side)->key($key)->grow(false)])
            ->from('md')->extraAttributes(['class'=>'domains-row'.($pending?' domains-row-pending':''),'data-piece'=>'sheet-row']);
    }
    /** A state word with its mark. Plain Filament shows the same word as a badge. */
    public static function state(TextEntry $entry,?string $tone,?string $record=null): TextEntry
    { return $entry->badge()->color($tone)->extraEntryWrapperAttributes(StatusMark::attributes($tone,$record)); }
    /** Quiet by default. Pass danger for a stop. */
    public static function button(Action $action,string $color='gray'): Action
    { return $action->button()->outlined()->color($color)->size('sm'); }
    /** Every row says Edit and screen readers hear which row. The dialog keeps its own title. */
    public static function edit(Action $action,string $row): Action
    { return self::button($action->modalHeading($action->getModalHeading())->label('Edit'))->extraAttributes(['aria-label'=>'Edit '.$row]); }
    /** A button that can't run now. It stays in the tab order and says why on hover and on focus. */
    public static function blocked(string $name,string $label,string $row,string $reason): Action
    {
        return self::button(Action::make($name)->label($label)->tooltip($reason)->livewireClickHandlerEnabled(false)
            ->extraAttributes(['aria-disabled'=>'true','aria-label'=>$label.' '.$row.'. '.$reason,'class'=>'domains-blocked']));
    }
    /** Finished buttons drawn beside a row or under a notice. */
    public static function buttons(array $html): HtmlString
    { return new HtmlString('<div class="fi-ac domains-actions">'.implode('',$html).'</div>'); }
    public static function link(string $name,string $label,?string $url,bool $external=false): Action
    {
        $link=Action::make($name)->label($label)->link()->size('sm')->url($url)->visible($url!==null);
        return $external?$link->openUrlInNewTab()->icon('heroicon-m-arrow-top-right-on-square')->iconPosition('after'):$link;
    }
    /** "Synced Nov 1, 2026, 2:13 AM PST": the viewer's time zone, UTC on hover. Without script it reads in the site's staff zone, named. */
    public static function moment(int|string $at,string $before=''): HtmlString
    {
        $iso=is_int($at)?gmdate('c',$at):$at;$fallback=is_int($at)?StaffTime::at($at):$at;
        try { $utc=CarbonImmutable::parse($iso)->utc()->format('M j, Y, g:i A').' UTC'; } catch (\Throwable) { $utc=null; }
        $time=view('gateways.ae-stripe::panel.moment',['at'=>$iso,'fallback'=>$fallback])->render();
        return new HtmlString(($before===''?'':e($before).' ').($utc===null?$time:'<span title="'.e($utc).'">'.$time.'</span>'));
    }
    /** A trace step for something that happened: its reason, then when and who. A step with no time says Missing and why. */
    public static function event(string $label,?string $sub,int|string|null $at,string $by,string $tone='gray',?string $value=null,?string $noTime=null): array
    {
        $when=$at===null?e(Missing::LABEL.($noTime===null?'':'. '.$noTime)):self::moment($at)->toHtml();
        return ['label'=>$label,'value'=>$value,'tone'=>$tone,'detail'=>new HtmlString(($sub?'<div>'.e($sub).'</div>':'').'<div class="domains-trace-when">'.$when.', '.e($by).'</div>')];
    }
    /** Steps on a line, each with its mark: a list to screen readers, plain lines without a skin.
     * A step is {label, value, tone, detail, help, buttons}. Unknown tones draw as OK.
     * @param list<array> $steps */
    public static function trace(array $steps): Group
    {
        return Group::make(array_map(static function(array $step): Group {
            $body=[Html::make(new HtmlString(view('domains::admin.trace-step',$step)->render()))];
            if(!empty($step['buttons'])){$body[]=Actions::make($step['buttons']);}
            return Group::make($body)->extraAttributes(['class'=>'domains-trace-item','role'=>'listitem','data-domains-tone'=>StatusMark::tone($step['tone'])??'ok','data-domains-record'=>'trace-'.md5($step['label'])]);
        },$steps))->extraAttributes(['class'=>'domains-trace','role'=>'list','data-piece'=>'sheet-trace']);
    }
}
