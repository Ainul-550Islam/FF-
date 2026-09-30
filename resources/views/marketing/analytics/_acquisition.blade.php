<div class="row" style="display: flex; gap: 20px; margin-bottom: 24px; flex-wrap: wrap">
    <div class="card" style="flex: 1 1 340px">
        <h3 style="margin-top: 0">🌐 Top Acquisition Sources</h3>
        <p class="muted" style="font-size: 0.85rem">Highest volume inbound traffic channels.</p>

        @if ($topSources->isEmpty())
            <p class="muted" style="padding: 16px 0">No source attribution data yet.</p>
        @else
            <table style="width: 100%; border-collapse: collapse; margin-top: 12px">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1)">
                        <th style="padding: 8px">Source (utm_source)</th>
                        <th style="padding: 8px; text-align: right">Attributed Touches</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($topSources as $src)
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05)">
                            <td style="padding: 8px"><code>{{ $src->source }}</code></td>
                            <td style="padding: 8px; text-align: right; font-weight: bold">{{ number_format($src->count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card" style="flex: 1 1 340px">
        <h3 style="margin-top: 0">🎯 Top UTM Campaigns</h3>
        <p class="muted" style="font-size: 0.85rem">Most active marketing initiatives by tracked visits.</p>

        @if ($topCampaigns->isEmpty())
            <p class="muted" style="padding: 16px 0">No campaign tracking data yet.</p>
        @else
            <table style="width: 100%; border-collapse: collapse; margin-top: 12px">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1)">
                        <th style="padding: 8px">Campaign (utm_campaign)</th>
                        <th style="padding: 8px; text-align: right">Attributed Touches</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($topCampaigns as $cmp)
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05)">
                            <td style="padding: 8px"><code>{{ $cmp->campaign }}</code></td>
                            <td style="padding: 8px; text-align: right; font-weight: bold">{{ number_format($cmp->count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
