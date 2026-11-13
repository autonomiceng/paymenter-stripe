{{-- Synced from Domains 08c94293c3a93f4afcd4704186dc0cf420dc5ea3 Domains/resources/views/components/panel/head-title.blade.php by tools/panel_sync.py. Change it in Domains and run the script again. --}}
{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A page title (Panel\Head) with its Demo or Test tag. --}}
<span class="domains-panel-head-title" data-piece="head-title">{{ $domain ? \Paymenter\Extensions\Gateways\AeStripe\Panel\Presentation\DomainName::html($title) : $title }}</span>{{ \Paymenter\Extensions\Gateways\AeStripe\Panel\Support\DemoData::label($demo, $test, inline: true) }}
