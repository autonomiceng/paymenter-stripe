<x-filament::section role="alert" icon="heroicon-o-exclamation-triangle" icon-color="warning">
    <x-slot name="heading">Core Stripe and AE Stripe are both on.</x-slot>
    <p>Customers are using core Stripe.</p>
    @foreach ($gateways as $gateway)
        <div class="mt-2">
            <p>Core Stripe #{{ $gateway['id'] }}</p>
            @if ($gateway['hasReferences'])
                <p>Pause checkout, collection and webhook delivery, then review the adoption plan:</p>
                <code>php artisan ae-stripe:adopt-bundled --confirm-paused --source-gateway={{ $gateway['id'] }}</code>
            @elseif ($gateway['canUpdate'])
                <form method="POST" action="{{ route('extensions.gateways.ae-stripe.disable-core') }}">
                    @csrf
                    <input type="hidden" name="gateway_id" value="{{ $gateway['id'] }}">
                    <x-filament::button type="submit" color="warning">Turn off core Stripe</x-filament::button>
                </form>
            @else
                <p>Ask a staff member with gateway update permission to turn off core Stripe.</p>
            @endif
        </div>
    @endforeach
</x-filament::section>
