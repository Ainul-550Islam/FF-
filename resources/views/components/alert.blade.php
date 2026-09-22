@props(['type' => 'info'])

@php
    // Errors and warnings are announced assertively; success/info politely.
    $role = in_array($type, ['error', 'warning'], true) ? 'alert' : 'status';
@endphp

<div role="{{ $role }}" {{ $attributes->merge(['class' => 'alert alert-'.$type]) }}>
    {{ $slot }}
</div>
