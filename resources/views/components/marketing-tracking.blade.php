{{--
    Phase 20 — consent-aware marketing tracker bootstrap.
    - First-party event pipe (window.ffTrack) always available: it talks to
      our own server only.
    - Third-party tags (GA4/GTM/Meta/TikTok) load ONLY when the visitor
      granted consent for that category AND the credential is configured.
    - Everything is inert by default: no ids configured → nothing emitted.
--}}

@php
    $consentCookie = (string) config('marketing.consent.cookie', 'ff_consent');
    $raw = (string) request()->cookie($consentCookie);
    $consent = json_decode($raw, true);
    $analyticsAllowed = is_array($consent) && ($consent['analytics'] ?? false) === true;
    $marketingAllowed = is_array($consent) && ($consent['marketing'] ?? false) === true;
    $ga4 = (string) config('marketing.tracking.ga4_id');
    $gtm = (string) config('marketing.tracking.gtm_id');
    $meta = (string) config('marketing.tracking.meta_pixel_id');
    $tiktok = (string) config('marketing.tracking.tiktok_pixel_id');
@endphp

<script>
    window.FFArenaMarketing = {
        consent: {{ json_encode([
            'analytics' => $analyticsAllowed,
            'marketing' => $marketingAllowed,
            'version' => (string) config('marketing.consent.policy_version'),
        ]) }},
        eventUrl: {{ json_encode(route('marketing.event')) }},
        consentUrl: {{ json_encode(route('marketing.consent')) }}
    };

    // First-party conversion events (§40 taxonomy, validated server-side).
    window.ffTrack = function (name, properties) {
        try {
            var payload = JSON.stringify({ name: name, properties: properties || {} });
            if (navigator.sendBeacon) {
                navigator.sendBeacon(window.FFArenaMarketing.eventUrl,
                    new Blob([payload], { type: 'application/json' }));
            } else {
                fetch(window.FFArenaMarketing.eventUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(
                            (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
                    },
                    body: payload
                });
            }
        } catch (e) { /* measurement must never break the page */ }
    };
</script>

@if ($analyticsAllowed && $ga4 !== '')
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4 }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $ga4 }}');
    </script>
@endif

@if ($analyticsAllowed && $gtm !== '')
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $gtm }}');</script>
@endif

@if ($marketingAllowed && $meta !== '')
    <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '{{ $meta }}');
        fbq('track', 'PageView');
    </script>
@endif

@if ($marketingAllowed && $tiktok !== '')
    <script>
        !function (w, d, t) { w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};ttq.load('{{ $tiktok }}');ttq.page(); }(window, document, 'ttq');
    </script>
@endif
