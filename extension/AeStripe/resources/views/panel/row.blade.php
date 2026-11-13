{{-- Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/resources/views/components/panel/row.blade.php by tools/panel_sync.py. Change it in Domains and run the script again. --}}
{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One sheet row (Panel\Row, Panel\SettingsRow): the label with its "?" on the last word, the value with any line under it, then the action column. A notice has no label. Without a skin it reads as stacked lines. data-piece names the piece (row, settings-row or notice), so a walk can crop it. --}}
@php preg_match('/^(.*\s)?(\S+)$/su', $label, $words); @endphp
<div class="domains-panel-row" id="{{ $id }}" data-piece="{{ ['setting' => 'settings-row', 'notice' => 'notice'][$kind] ?? 'row' }}" data-domains-row="{{ $kind }}" @if ($waits) data-domains-wait @endif>
@if ($kind !== 'notice')<div class="domains-panel-row-k">@if ($help !== null){{ $words[1] ?? '' }}<span class="domains-panel-nw">{{ $words[2] ?? $label }}{!! $help !!}</span>@else{{ $label }}@endif</div>@endif
<div class="domains-panel-row-v">{{ $value }}@if ($result !== null){{ $result }}@endif
@if ($blocked !== null)<div class="domains-panel-sub" id="{{ $why }}">{{ $blocked }}@if ($fix !== null) {!! $fix !!}@endif</div>@endif</div>
<div class="domains-panel-row-a">@if ($live !== null){{ $live }}@elseif ($actions !== null){!! $actions !!}@endif</div>
</div>
