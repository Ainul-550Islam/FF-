<div class="card" style="margin-bottom: 24px">
    <h3 style="margin-top: 0">📈 Conversion & Lifecycle Events</h3>
    <p class="muted" style="font-size: 0.85rem">Granular occurrences recorded through the server-side event pipeline.</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-top: 16px">
        <div style="padding: 12px; background: rgba(255,255,255,0.02); border-radius: 4px; border: 1px solid rgba(255,255,255,0.06)">
            <span class="muted" style="font-size: 0.8rem">Register Complete</span>
            <h3 style="margin: 4px 0 0; color: #818cf8">{{ number_format($eventsCounts['register_complete'] ?? 0) }}</h3>
        </div>
        <div style="padding: 12px; background: rgba(255,255,255,0.02); border-radius: 4px; border: 1px solid rgba(255,255,255,0.06)">
            <span class="muted" style="font-size: 0.8rem">Login Complete</span>
            <h3 style="margin: 4px 0 0">{{ number_format($eventsCounts['login_complete'] ?? 0) }}</h3>
        </div>
        <div style="padding: 12px; background: rgba(255,255,255,0.02); border-radius: 4px; border: 1px solid rgba(255,255,255,0.06)">
            <span class="muted" style="font-size: 0.8rem">Payment Checkout Starts</span>
            <h3 style="margin: 4px 0 0">{{ number_format($eventsCounts['payment_start'] ?? 0) }}</h3>
        </div>
        <div style="padding: 12px; background: rgba(255,255,255,0.02); border-radius: 4px; border: 1px solid rgba(255,255,255,0.06)">
            <span class="muted" style="font-size: 0.8rem">Successful Payments</span>
            <h3 style="margin: 4px 0 0; color: #34d399">{{ number_format($eventsCounts['payment_success'] ?? 0) }}</h3>
        </div>
        <div style="padding: 12px; background: rgba(255,255,255,0.02); border-radius: 4px; border: 1px solid rgba(255,255,255,0.06)">
            <span class="muted" style="font-size: 0.8rem">Referral Clicks</span>
            <h3 style="margin: 4px 0 0">{{ number_format($eventsCounts['referral_click'] ?? 0) }}</h3>
        </div>
        <div style="padding: 12px; background: rgba(255,255,255,0.02); border-radius: 4px; border: 1px solid rgba(255,255,255,0.06)">
            <span class="muted" style="font-size: 0.8rem">Referral Signups</span>
            <h3 style="margin: 4px 0 0; color: #38bdf8">{{ number_format($eventsCounts['referral_signup'] ?? 0) }}</h3>
        </div>
    </div>
</div>
