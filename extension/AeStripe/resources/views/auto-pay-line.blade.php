{{-- The Auto Pay consent line, word for word. Pass $consent from the gateway's autoPayConsent(); $id and $class are optional. --}}
<span @isset($id) id="{{ $id }}" @endisset class="{{ $class ?? 'block text-sm' }}">{{ $consent['text'] }}@if($consent['terms_url']) <a href="{{ $consent['terms_url'] }}" target="_blank" rel="noopener" class="underline">{{ $consent['terms_label'] }}</a>@endif</span>
