{{-- Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/resources/views/components/panel/switch.blade.php by tools/panel_sync.py. Change it in Domains and run the script again. --}}
{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A setting row's switch (Panel\SettingsRow::toggle): the page's own action, drawn as a toggle. Blocked, it keeps focus and points to its reason. The word On or Off stays in the row's value. --}}
@php
    $click = $why === null && ! $action->isDisabled() ? $action->getLivewireClickHandler() : null;
    $colors = \Illuminate\Support\Arr::toCssClasses(\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, $on ? 'primary' : 'gray'));
@endphp
<button type="button" role="switch" data-piece="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $name }}"
    @if ($click) wire:click="{{ $click }}" wire:loading.attr="disabled" @elseif ($why !== null) aria-disabled="true" aria-describedby="{{ $why }}" @else disabled @endif
    class="fi-toggle domains-panel-switch {{ $on ? 'fi-toggle-on' : 'fi-toggle-off' }} {{ $colors }}"><div><div aria-hidden="true"></div><div aria-hidden="true"></div></div></button>
