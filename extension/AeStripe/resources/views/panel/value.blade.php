{{-- Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/resources/views/components/panel/value.blade.php by tools/panel_sync.py. Change it in Domains and run the script again. --}}
{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One panel value (Panel\Value): a state with its mark, a plain word or a fact, then a sentence under it and, for staff, a line of times and codes. Without a skin it reads as stacked text. --}}
@props(['value'])
<div class="domains-panel-val" data-piece="value" data-domains-kind="{{ $value->kind }}">
@if ($value->tone)<span class="domains-panel-st" data-domains-tone="{{ $value->tone->value }}" @if ($value->record !== null) data-domains-record="{{ $value->record }}" @endif>{{ $value->word }}</span>
@elseif ($value->fact)@include('gateways.ae-stripe::panel.fact', ['fact' => $value->fact])
@else<span class="domains-panel-plain">{{ $value->word }}</span>@endif
@if ($value->sub !== null)<div class="domains-panel-sub">{{ $value->sub }}@if ($value->subAt !== null), @include('gateways.ae-stripe::panel.fact', ['fact' => $value->subAt]).@endif</div>@endif
@if ($extra = $value->shownExtra())<div class="domains-panel-sub domains-panel-extra">@foreach ($extra as $fact)@include('gateways.ae-stripe::panel.fact', ['fact' => $fact])@if (!$loop->last), @endif @endforeach</div>@endif
</div>
