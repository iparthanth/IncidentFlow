@php
    $severity = strtoupper((string) ($payload['severity'] ?? ''));
    $reference = (string) ($payload['incident_reference'] ?? '');
    $title = (string) ($payload['incident_title'] ?? 'Incident');
@endphp

<x-mail::message>
# {{ $reference }} — {{ $title }}

**Severity:** {{ $severity !== '' ? $severity : 'Unknown' }}
**Status:** {{ ucfirst(str_replace('_', ' ', (string) ($payload['status'] ?? 'unknown'))) }}

{{ $notification->body }}

@if(! empty($payload['note']))
> {{ $payload['note'] }}
@endif

@if(! empty($payload['from']) && ! empty($payload['to']))
The status changed from **{{ $payload['from'] }}** to **{{ $payload['to'] }}**.
@endif

<x-mail::button :url="$url">
Open the incident
</x-mail::button>

{{-- The reference is repeated in plain text so it survives a client that
     strips buttons, and so an on-call engineer can search their inbox for it. --}}
You are receiving this because you are involved in {{ $reference }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
