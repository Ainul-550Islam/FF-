@extends('layouts.app')
@section('title', 'Reconciliation Report 8 - Financial Totals Must Reconcile')
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">📊 Reconciliation Report 8 - G1 Constraint</h1>
<p style="color: var(--text-muted);">Financial totals MUST reconcile, if differences STOP, do not declare complete - Gold wallets gem wallets transactions - Report 8</p>
<div class="card" style="padding: 20px; margin-top: 20px;">
<h3>Gold Wallet Reconciliation 8</h3>
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 12px;">
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 800;">5000</div><div style="font-size: 11px; color: var(--text-muted);">Initial Gold</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 800;">{{ $goldReconcile['credits'] ?? 0 }}</div><div style="font-size: 11px; color: var(--text-muted);">Credits</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 800;">{{ $goldReconcile['debits'] ?? 0 }}</div><div style="font-size: 11px; color: var(--text-muted);">Debits</div></div>
<div style="background: {{ ($goldReconcile['is_balanced'] ?? true) ? '#27ae60' : '#e74c3c' }}; color: white; padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 800;">{{ $goldReconcile['wallet_balance'] ?? 0 }}</div><div style="font-size: 11px;">Wallet Balance {{ ($goldReconcile['is_balanced'] ?? true) ? '✅ Balanced' : '❌ Mismatch STOP' }}</div></div>
</div>
<div style="margin-top: 12px; font-size: 12px; color: var(--text-muted);">Computed: {{ $goldReconcile['computed_balance'] ?? 0 }} | Difference: {{ $goldReconcile['difference'] ?? 0 }} | Transactions: {{ $goldReconcile['total_transactions'] ?? 0 }} | Must be 0 difference or STOP - G1 financial totals must reconcile</div>
</div>
<div class="card" style="padding: 20px; margin-top: 20px;">
<h3>Gem Wallet Reconciliation 8</h3>
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 12px;">
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 800;">10</div><div style="font-size: 11px; color: var(--text-muted);">Initial Gems</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 800;">{{ $gemReconcile['credits'] ?? 0 }}</div><div style="font-size: 11px; color: var(--text-muted);">Credits</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 800;">{{ $gemReconcile['debits'] ?? 0 }}</div><div style="font-size: 11px; color: var(--text-muted);">Debits</div></div>
<div style="background: {{ ($gemReconcile['is_balanced'] ?? true) ? '#9b59b6' : '#e74c3c' }}; color: white; padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 800;">{{ $gemReconcile['wallet_balance'] ?? 0 }}</div><div style="font-size: 11px;">Wallet Balance {{ ($gemReconcile['is_balanced'] ?? true) ? '✅ Balanced' : '❌ Mismatch STOP' }}</div></div>
</div>
<div style="margin-top: 12px; font-size: 12px; color: var(--text-muted);">Computed: {{ $gemReconcile['computed_balance'] ?? 0 }} | Difference: {{ $gemReconcile['difference'] ?? 0 }} | Must be 0 difference or STOP</div>
</div>
<div class="card" style="padding: 16px; margin-top: 20px;">
<h4>Report 8 - Gameberry Features Reconciliation</h4>
<p style="font-size: 12px; color: var(--text-muted);">Report 8 covers Gameberry features: 250+ dice collection max 52 Facebook-only exchange lucky dice gem reward, 6-step league Bronze Silver Gold Platinum Diamond Titan Top 20% promotion Top 40 demotion Titan badges Level 4 unlock, Game Buddies max 25 private table code/link sharing challenge button team-up mode classic/master/quick chat emojis weekly events gold at stake magic chest video ads free gold gems spin2win auto mode hide online status notify friends Level 4 Bronze unlock referral BGI20 ₹25 scratch cards gold wallets gem wallets reconciliation.</p>
</div>
</div>
@endsection
