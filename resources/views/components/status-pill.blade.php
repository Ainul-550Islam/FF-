@props(['status' => 'neutral', 'label' => null])

@php
$map = [
    'success' => 'success',
    'completed' => 'success',
    'active' => 'success',
    'verified' => 'success',
    'online' => 'success',
    'succeeded' => 'success',
    'warning' => 'warning',
    'pending' => 'warning',
    'processing' => 'warning',
    'draft' => 'warning',
    'offline' => 'warning',
    'danger' => 'danger',
    'failed' => 'danger',
    'banned' => 'danger',
    'rejected' => 'danger',
    'error' => 'danger',
    'info' => 'info',
    'neutral' => 'neutral',
];
$class = $map[strtolower($status)] ?? 'neutral';
$display = $label ?? ucfirst($status);
@endphp

<span {{ $attributes->merge(['class' => "status-pill $class"]) }} aria-label="Status: {{ $display }}">
    {{ $display }}
</span>
