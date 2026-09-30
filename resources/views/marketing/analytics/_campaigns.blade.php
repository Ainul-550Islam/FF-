<div class="card" style="margin-bottom: 24px">
    <h3 style="margin-top: 0">🚀 Campaign Landing Performance</h3>
    <p class="muted" style="font-size: 0.85rem">Performance of active landing pages created in the campaign system.</p>

    @if ($campaignRecords->isEmpty())
        <p class="muted" style="padding: 16px 0">No active campaign pages configured.</p>
    @else
        <div style="overflow-x: auto; margin-top: 12px">
            <table style="width: 100%; border-collapse: collapse">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1)">
                        <th style="padding: 10px">Campaign Name</th>
                        <th style="padding: 10px">Landing Slug</th>
                        <th style="padding: 10px">UTM Key</th>
                        <th style="padding: 10px; text-align: right">Touches</th>
                        <th style="padding: 10px; text-align: right">Conversions</th>
                        <th style="padding: 10px; text-align: right">CR (%)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($campaignRecords as $item)
                        @php
                            $c = $item['campaign'];
                            $cr = $item['touches'] > 0 ? round(($item['conversions'] / $item['touches']) * 100, 1) : 0;
                        @endphp
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05)">
                            <td style="padding: 10px"><strong>{{ $c->name }}</strong></td>
                            <td style="padding: 10px"><a href="{{ route('marketing.campaigns.show', ['slug' => $c->slug]) }}" target="_blank">/campaign/{{ $c->slug }}</a></td>
                            <td style="padding: 10px"><code>{{ $c->campaignKey() }}</code></td>
                            <td style="padding: 10px; text-align: right">{{ number_format($item['touches']) }}</td>
                            <td style="padding: 10px; text-align: right; font-weight: bold; color: #34d399">{{ number_format($item['conversions']) }}</td>
                            <td style="padding: 10px; text-align: right">{{ $cr }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
