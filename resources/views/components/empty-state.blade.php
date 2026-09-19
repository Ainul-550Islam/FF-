@props(['icon' => '📭', 'title' => 'No data', 'text' => null, 'action' => null, 'actionHref' => null])

<div {{ $attributes->merge(['class' => 'empty-state']) }} role="status" aria-live="polite">
    <div class="empty-state-icon" aria-hidden="true">{{ $icon }}</div>
    <h3 class="empty-state-title">{{ $title }}</h3>
    @if($text)
        <p class="empty-state-text">{{ $text }}</p>
    @endif
    @if($slot->isNotEmpty())
        <div class="empty-state-text">{{ $slot }}</div>
    @endif
    @if($action && $actionHref)
        <a href="{{ $actionHref }}" class="btn btn-primary">{{ $action }}</a>
    @elseif($action)
        <div style="margin-top: 16px;">{{ $action }}</div>
    @endif
</div>
