@props(['type' => 'info', 'dismissible' => false, 'persistent' => false])

@php
$types = [
    'success' => 'alert-success',
    'error' => 'alert-danger',
    'danger' => 'alert-danger',
    'warning' => 'alert-warning',
    'info' => 'alert-info',
];
$class = $types[$type] ?? $types['info'];
@endphp

<div {{ $attributes->merge(['class' => "alert $class"]) }} role="{{ $type === 'error' || $type === 'danger' ? 'alert' : 'status' }}" @if($persistent) data-persistent="true" @endif aria-live="{{ $type === 'error' || $type === 'danger' ? 'assertive' : 'polite' }}">
    <div style="flex: 1;">
        {{ $slot }}
    </div>
    @if($dismissible)
        <button type="button" onclick="this.closest('.alert').remove()" aria-label="Dismiss alert" class="btn btn-ghost btn-sm" style="margin-left: auto;">×</button>
    @endif
</div>
