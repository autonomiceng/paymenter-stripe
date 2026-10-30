@component('layouts.app')
    <div class="container mx-auto px-4 py-8">
        <p role="status">{{ $message }}</p>
        <a href="{{ $invoiceUrl }}" class="underline">Open invoice</a>
    </div>
@endcomponent
