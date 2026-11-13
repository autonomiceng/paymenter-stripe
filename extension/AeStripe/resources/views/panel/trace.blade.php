{{-- Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/resources/views/components/panel/trace.blade.php by tools/panel_sync.py. Change it in Domains and run the script again. --}}
{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Steps on one line (Panel\Trace): each step's mark and word come from its state, and a step with no state has neither; under the label, when, who and why. Without a skin it is a plain ordered list. --}}
<ol class="domains-panel-trace" data-piece="trace">
@foreach ($steps as $step)
<li class="domains-panel-step" @if ($step['tone'] !== null) data-domains-tone="{{ $step['tone']->value }}" @endif @if ($step['record'] !== null) data-domains-record="{{ $step['record'] }}" @endif><div class="domains-panel-step-tx"><div class="domains-panel-step-t">{{ $step['label'] }}</div>@if ($step['at'] !== null || $step['by'] !== null || $step['reason'] !== null)<div class="domains-panel-step-d">@if ($step['at'] !== null)@include('gateways.ae-stripe::panel.fact', ['fact' => $step['at']])@endif{{ $step['by'] !== null ? ($step['at'] !== null ? ', by ' : 'By ').$step['by'] : '' }}{{ $step['reason'] !== null ? ($step['at'] !== null || $step['by'] !== null ? '. ' : '').$step['reason'] : '' }}</div>@endif</div>@if ($step['word'] !== null)<span class="domains-panel-step-w">{{ $step['word'] }}</span>@endif</li>
@endforeach
</ol>
