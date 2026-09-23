{{--
    Phase 20 — marketing consent / cookie preference banner.
    First render: nothing is decided, so third-party trackers stay off and
    the banner offers granular analytics/marketing choices plus reject-all.
    The decision posts to /marketing/consent (append-only ledger + cookie)
    and the banner disappears. A "Cookie settings" re-open link is rendered
    once a decision exists.
--}}

@php
    $consentCookie = (string) config('marketing.consent.cookie', 'ff_consent');
    $raw = (string) request()->cookie($consentCookie);
    $consent = json_decode($raw, true);
    $decided = is_array($consent);
@endphp

<div id="marketing-consent-root"
     data-consent-url="{{ route('marketing.consent') }}"
     data-cookie-name="{{ $consentCookie }}"
     data-initial="{{ json_encode($decided ? [
         'analytics' => (bool) ($consent['analytics'] ?? false),
         'marketing' => (bool) ($consent['marketing'] ?? false),
         'withdrawn' => (bool) ($consent['withdrawn'] ?? false),
         'decided' => true,
     ] : ['decided' => false]) }}"
     style="display: contents">
    <div id="consent-banner" class="card" role="dialog" aria-modal="false"
         aria-labelledby="consent-title"
         style="position: fixed; bottom: 12px; left: 12px; right: 12px; max-width: 720px; margin: 0 auto; z-index: 80; padding: 16px; display: none;">
        <h3 id="consent-title" style="margin: 0 0 6px">Cookies &amp; measurement</h3>
        <p class="muted" style="margin: 0 0 10px; font-size: .9rem">
            We use first-party analytics to measure which tournaments and campaigns matter.
            Advertising tags only load if you allow them. You can change this anytime.
        </p>
        <div class="row" style="gap: 14px; flex-wrap: wrap; margin-bottom: 10px">
            <label><input type="checkbox" id="consent-analytics" checked> Analytics (first-party)</label>
            <label><input type="checkbox" id="consent-marketing"> Marketing / ads</label>
        </div>
        <div class="row" style="gap: 8px; flex-wrap: wrap">
            <button type="button" id="consent-accept-all" class="btn btn-green btn-sm">Accept all</button>
            <button type="button" id="consent-save" class="btn btn-sm">Save choices</button>
            <button type="button" id="consent-reject" class="btn btn-sm btn-secondary">Reject non-essential</button>
        </div>
    </div>
</div>

<script>
    (function () {
        var root = document.getElementById('marketing-consent-root');
        if (!root) return;
        var banner = document.getElementById('consent-banner');
        var url = root.getAttribute('data-consent-url');
        var initial = {};
        try { initial = JSON.parse(root.getAttribute('data-initial') || '{}'); } catch (e) { initial = {}; }

        function xsrf() {
            return decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        }

        function post(consent, withdraw, done) {
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({
                    analytics: !!consent.analytics,
                    marketing: !!consent.marketing,
                    withdraw: !!withdraw
                })
            }).then(function () { if (done) done(); })
              .catch(function () { if (done) done(); });
        }

        function hide() { banner.style.display = 'none'; }

        function open() {
            if (initial.analytics) document.getElementById('consent-analytics').checked = true;
            if (initial.marketing) document.getElementById('consent-marketing').checked = true;
            banner.style.display = 'block';
        }

        document.getElementById('consent-accept-all').addEventListener('click', function () {
            post({ analytics: true, marketing: true }, false, function () {
                initial = { analytics: true, marketing: true, decided: true };
                hide(); location.reload();
            });
        });
        document.getElementById('consent-reject').addEventListener('click', function () {
            post({ analytics: false, marketing: false }, false, function () {
                initial = { analytics: false, marketing: false, decided: true };
                hide(); location.reload();
            });
        });
        document.getElementById('consent-save').addEventListener('click', function () {
            var analytics = document.getElementById('consent-analytics').checked;
            var marketing = document.getElementById('consent-marketing').checked;
            post({ analytics: analytics, marketing: marketing }, false, function () {
                initial = { analytics: analytics, marketing: marketing, decided: true };
                hide(); location.reload();
            });
        });

        // Re-open entry point for withdrawal / changes.
        var settings = document.getElementById('consent-settings-link');
        if (settings) settings.addEventListener('click', function (e) { e.preventDefault(); open(); });

        if (!initial.decided) { open(); }
    })();
</script>
