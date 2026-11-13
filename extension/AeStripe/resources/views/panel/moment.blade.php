{{-- Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/resources/views/partials/moment.blade.php by tools/panel_sync.py. Change it in Domains and run the script again. --}}
{{-- A moment in time (last checked, request time). The script shows it in the viewer's time zone. The server text is the fallback without script: the site's staff zone, named (StaffTime). --}}
@php
    $instant = null;
    try { $instant = $at instanceof \DateTimeInterface ? \Carbon\CarbonImmutable::instance($at) : (is_string($at) && $at !== '' ? \Carbon\CarbonImmutable::parse($at) : null); } catch (\Throwable) {}
@endphp
@if ($instant)<time datetime="{{ $instant->toIso8601String() }}" data-domains-time="{{ $style ?? 'full' }}" wire:ignore wire:key="moment-{{ $instant->timestamp }}" x-data x-init="const date = new Date($el.dateTime); if (!isNaN(date)) $el.textContent = new Intl.DateTimeFormat(undefined, {year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short'}).format(date)">{{ \Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation\StaffTime::at($instant->getTimestamp()) }}</time>@else{{ $fallback ?? 'Never' }}@endif
