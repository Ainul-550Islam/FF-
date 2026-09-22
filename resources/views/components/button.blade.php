@props(['href' => null, 'variant' => 'default', 'size' => null, 'type' => 'button'])

@php
    $class = 'btn'
        . ($variant && $variant !== 'default' ? ' btn-'.$variant : '')
        . ($size ? ' btn-'.$size : '');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</button>
@endif
