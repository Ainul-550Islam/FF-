<div class="card" style="margin-bottom: 24px">
    <h3 style="margin-top: 0">🔻 Acquisition & Conversion Funnel</h3>
    <p class="muted" style="font-size: 0.85rem">Step-by-step conversion progression derived from authentic touchpoints.</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0">
        <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 6px; border-left: 3px solid #38bdf8">
            <span class="muted" style="font-size: 0.8rem; text-transform: uppercase">Stage 1: Top of Funnel</span>
            <h2 style="margin: 6px 0 2px">{{ number_format($funnel['touches']) }}</h2>
            <p class="muted" style="margin: 0; font-size: 0.85rem">Unique Visitors / Impressions</p>
        </div>

        <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 6px; border-left: 3px solid #818cf8">
            <span class="muted" style="font-size: 0.8rem; text-transform: uppercase">Stage 2: Registrations</span>
            <h2 style="margin: 6px 0 2px">{{ number_format($funnel['registrations']) }}</h2>
            <p style="margin: 0; font-size: 0.85rem; color: #818cf8; font-weight: bold">
                {{ $funnel['reg_conversion_rate'] }}% <span class="muted" style="font-weight: normal">from top</span>
            </p>
        </div>

        <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 6px; border-left: 3px solid #34d399">
            <span class="muted" style="font-size: 0.8rem; text-transform: uppercase">Stage 3: Paid Conversions</span>
            <h2 style="margin: 6px 0 2px">{{ number_format($funnel['payments']) }}</h2>
            <p style="margin: 0; font-size: 0.85rem; color: #34d399; font-weight: bold">
                {{ $funnel['payment_conversion_rate'] }}% <span class="muted" style="font-weight: normal">from signups</span>
            </p>
        </div>

        <div style="padding: 16px; background: rgba(255,255,255,0.03); border-radius: 6px; border-left: 3px solid #f87171">
            <span class="muted" style="font-size: 0.8rem; text-transform: uppercase">Payment Friction</span>
            <h2 style="margin: 6px 0 2px; color: #f87171">{{ number_format($funnel['payment_failures']) }}</h2>
            <p class="muted" style="margin: 0; font-size: 0.85rem">Unsuccessful Payment Attempts</p>
        </div>
    </div>
</div>
