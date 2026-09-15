@props(['title', 'icon' => null])

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    @if ($icon)
        <div class="empty-icon" aria-hidden="true">{{ $icon }}</div>
    @endif
    <h3>{{ $title }}</h3>
    <p>{{ $slot }}</p>
</div>
