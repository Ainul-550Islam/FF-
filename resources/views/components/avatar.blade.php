@props(['user' => null, 'size' => 'md', 'editable' => false, 'src' => null, 'initials' => null, 'alt' => null])

@php
$sizes = [
    'sm' => 'avatar-sm',
    'md' => '',
    'lg' => 'avatar-lg',
    'xl' => 'avatar-xl',
];
$sizeClass = $sizes[$size] ?? '';
$userInitials = $initials ?? ($user->initials ?? '??');
$userAlt = $alt ?? ($user->display_name_or_name ?? 'User avatar');
$avatarUrl = $src ?? ($user->avatar_url ?? null);
$hasAvatar = $user ? $user->hasAvatar() : !empty($src);
@endphp

<div {{ $attributes->merge(['class' => "avatar $sizeClass avatar-upload"]) }} data-initials="{{ $userInitials }}">
    @if($hasAvatar && $avatarUrl)
        <img src="{{ $avatarUrl }}" alt="{{ $userAlt }}" width="{{ $size === 'xl' ? 120 : ($size === 'lg' ? 80 : ($size === 'sm' ? 32 : 40)) }}" height="{{ $size === 'xl' ? 120 : ($size === 'lg' ? 80 : ($size === 'sm' ? 32 : 40)) }}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;" data-avatar-preview data-fallback="{{ $avatarUrl }}">
        <div class="avatar-fallback" style="display: none;">{{ $userInitials }}</div>
    @else
        <div class="avatar-fallback" data-avatar-preview data-initials="{{ $userInitials }}">{{ $userInitials }}</div>
    @endif
    
    @if($editable)
        <div class="avatar-upload-overlay">
            <span>Change</span>
        </div>
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" data-avatar-input data-preview-target="[data-avatar-preview]" aria-label="Upload avatar">
    @endif
</div>
