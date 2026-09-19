@props(['variant' => 'primary', 'size' => 'md', 'type' => 'button', 'href' => null])

@php
$variants = [
    'primary' => 'btn-primary',
    'secondary' => 'btn-secondary',
    'ghost' => 'btn-ghost',
    'danger' => 'btn-danger',
];
$sizes = [
    'sm' => 'btn-sm',
    'md' => '',
    'lg' => 'btn-lg',
];
$variantClass = $variants[$variant] ?? $variants['primary'];
$sizeClass = $sizes[$size] ?? '';
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => "btn $variantClass $sizeClass"]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => "btn $variantClass $sizeClass"]) }}>
        {{ $slot }}
    </button>
@endif
