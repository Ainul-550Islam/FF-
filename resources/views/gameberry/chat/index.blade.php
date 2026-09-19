@extends('layouts.app')
@section('title', 'Chat & Emojis - Private Table')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">💬 Chat & Emojis - Table {{ $table->code ?? '' }}</h1>
<p style="color: var(--text-muted);">LudoStar chat & emojis - Quick messages - System messages</p>
<div class="card" style="padding: 20px; margin-top: 20px;">
<div style="max-height: 400px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
@foreach($messages as $msg)
<div style="padding: 8px 12px; border-radius: 12px; background: {{ $msg->is_system ? 'var(--bg-tertiary)' : ($msg->user_id===auth()->id() ? 'var(--primary)' : 'var(--bg-secondary)') }}; color: {{ $msg->user_id===auth()->id() && !$msg->is_system ? 'white' : 'inherit' }}; align-self: {{ $msg->user_id===auth()->id() ? 'flex-end' : 'flex-start' }}; max-width: 80%; font-size: 13px;">
@if(!$msg->is_system)<div style="font-size: 10px; opacity: 0.7;">{{ $msg->user->name ?? 'User' }}</div>@endif
<div>{{ $msg->message }} @if($msg->type==='emoji') <span style="font-size: 16px;">{{ $msg->message }}</span> @endif</div>
</div>
@endforeach
</div>
<div style="display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 12px;">
@foreach($emojis as $key=>$emoji)
<form method="POST" action="{{ route('gameberry.chat.emoji', $table->code) }}" style="display: inline;">@csrf<input type="hidden" name="emoji_key" value="{{ $key }}"><button type="submit" style="background: var(--bg-secondary); border: none; border-radius: 6px; padding: 4px 8px; cursor: pointer;">{{ $emoji }}</button></form>
@endforeach
</div>
<div style="display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 12px;">
@foreach($quickMessages as $qm)
<form method="POST" action="{{ route('gameberry.chat.quick', $table->code) }}" style="display: inline;">@csrf<input type="hidden" name="quick_message" value="{{ $qm }}"><button type="submit" style="background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 999px; padding: 4px 10px; font-size: 11px; cursor: pointer;">{{ $qm }}</button></form>
@endforeach
</div>
<form method="POST" action="{{ route('gameberry.chat.send', $table->code) }}" style="display: flex; gap: 8px;">@csrf<input type="text" name="message" placeholder="Type message..." required maxlength="200" style="flex: 1; padding: 10px 14px; border-radius: 999px; border: 1px solid var(--border); background: var(--bg-secondary);"><button type="submit" class="btn btn-primary">Send 💬</button></form>
</div>
</div>
@endsection
