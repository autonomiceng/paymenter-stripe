<div role="alert" class="rounded-lg border border-warning-500 p-4">
    <p>Core Stripe and StripeSaved are both on. Customers are using core Stripe.</p>
    @foreach ($gateways as $gateway)
        <div class="mt-2">
            <p>Core Stripe #{{ $gateway['id'] }}</p>
            @if ($gateway['hasReferences'])
                <p>Pause checkout, collection and webhook delivery, then review the adoption plan:</p>
                <code>php artisan stripe-saved:adopt-bundled --confirm-paused --source-gateway={{ $gateway['id'] }}</code>
            @elseif ($gateway['canUpdate'])
                <form method="POST" action="{{ route('extensions.gateways.stripe-saved.disable-core') }}">
                    @csrf
                    <input type="hidden" name="gateway_id" value="{{ $gateway['id'] }}">
                    <x-filament::button type="submit" color="warning">Turn off core Stripe</x-filament::button>
                </form>
            @else
                <p>Ask a staff member with gateway update permission to turn off core Stripe.</p>
            @endif
        </div>
    @endforeach
</div>
