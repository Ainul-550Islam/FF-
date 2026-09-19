@extends('layouts.app')
@section('title', 'Join Private Table - Code/Link')
@section('content')
<div class="container" style="max-width: 600px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">🔗 Join Private Table</h1>
<p style="color: var(--text-muted);">Join via code or link - Gold at stake - Team Up mode - Classic Master Quick</p>
<div class="card" style="padding: 24px; margin-top: 20px;">
<form method="POST" action="{{ route('gameberry.private_tables.join', 'CODE') }}" id="joinForm">
@csrf
<div style="margin-bottom: 16px;">
<label style="display: block; font-weight: 600; margin-bottom: 6px;">Table Code (6-char uppercase)</label>
<input type="text" name="code" id="codeInput" placeholder="Enter 6-char code e.g. ABC123" required maxlength="6" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-secondary); text-transform: uppercase; letter-spacing: 4px; font-size: 18px; font-weight: 800; text-align: center;">
<div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Code sharing like LudoStar - 6-char uppercase</div>
</div>
<button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 16px;">Join Table - Gold at Stake 🎮</button>
</form>
<div style="margin-top: 16px; background: var(--bg-secondary); padding: 12px; border-radius: 8px; font-size: 12px;">
<strong>How to join:</strong><br>
• Enter 6-char code from friend<br>
• Or click shareable link directly<br>
• Need enough gold for bet (gold at stake)<br>
• Team Up mode auto assigns team_a/team_b<br>
• Auto mode on disconnect available<br>
• Chat & emojis in table
</div>
</div>
</div>
<script>
document.getElementById('joinForm').addEventListener('submit', function(e){
  e.preventDefault();
  const code = document.getElementById('codeInput').value.trim().toUpperCase();
  if(code.length===6){
    this.action = '/gameberry/private-tables/' + code + '/join';
    this.submit();
  }
});
</script>
@endsection
