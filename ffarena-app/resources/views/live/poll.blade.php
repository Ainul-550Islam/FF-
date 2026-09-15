@php
    $liveUrl = isset($tournament) ? route('tournaments.live', $tournament) : null;
    $liveSince = (int) ($liveRevision ?? 0);
    $liveInterval = (int) config('live.poll_interval_ms', 10000);
@endphp

@if ($liveUrl)
    <div id="live-feed"
         data-url="{{ $liveUrl }}"
         data-since="{{ $liveSince }}"
         data-interval="{{ $liveInterval }}"
         style="margin-bottom: 18px">
        <section class="card" aria-labelledby="live-feed-heading" style="margin-bottom: 0">
            <h3 id="live-feed-heading" class="row" style="gap: 10px">
                <span class="live-dot" aria-hidden="true">●</span>
                LIVE
                <span class="muted" style="font-size: .8rem; font-weight: 400">auto-updates</span>
            </h3>

            <ul id="live-feed-items" class="live-feed-list" aria-live="polite" aria-label="Live updates"></ul>

            <button id="live-refresh" type="button" class="btn btn-sm btn-cyan" hidden
                    style="margin-top: 10px">
                🔄 New results — refresh page
            </button>
        </section>
    </div>

    <script>
    (function () {
        var feed = document.getElementById('live-feed');
        if (!feed) { return; }

        var url = feed.dataset.url;
        var since = parseInt(feed.dataset.since || '0', 10);
        var interval = parseInt(feed.dataset.interval || '10000', 10);
        var list = document.getElementById('live-feed-items');
        var refreshBtn = document.getElementById('live-refresh');
        var resultTypes = ['match.score_submitted', 'match.completed', 'match.disputed', 'match.resolved'];

        // Backoff: pause in hidden tabs; on failure double the wait (cap 2min);
        // on success reset to the configured interval. No retry storm.
        var currentInterval = interval;
        var maxInterval = 120000;
        var timer = null;

        function label(e) {
            var p = e.payload || {};
            var m = p.match_no ? ('Match ' + p.match_no) : '';
            switch (e.type) {
                case 'match.score_submitted': return m + ': ' + p.team + ' scored ' + p.kills + ' kills (#' + p.placement + ')';
                case 'match.completed': return m + ' completed — ' + p.winner + ' wins';
                case 'match.disputed': return m + ' disputed';
                case 'match.resolved': return m + ' dispute resolved — ' + p.winner + ' wins';
                case 'match.started': return m + ' started';
                case 'team.checked_in': return p.team + ' checked in';
                case 'team.registered': return p.team + ' registered' + (p.waitlisted ? ' (waitlisted)' : '');
                case 'team.withdrawn': return p.team + ' withdrew';
                default: return e.type;
            }
        }

        function prepend(e) {
            var li = document.createElement('li');
            li.textContent = '• ' + label(e);
            list.insertBefore(li, list.firstChild);
            while (list.children.length > 12) { list.removeChild(list.lastChild); }
        }

        function tick() {
            if (document.hidden) { schedule(); return; }

            fetch(url + '?since=' + since, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                .then(function (d) {
                    currentInterval = interval; // healthy — reset backoff
                    since = parseInt(d.revision || since, 10);
                    var events = d.events || [];
                    if (!events.length) { return; }
                    events.slice().reverse().forEach(prepend);
                    if (events.some(function (e) { return resultTypes.indexOf(e.type) !== -1; })) {
                        refreshBtn.hidden = false;
                    }
                })
                .catch(function () {
                    currentInterval = Math.min(currentInterval * 2, maxInterval);
                });
            schedule();
        }

        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(tick, currentInterval);
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () { window.location.reload(); });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { clearTimeout(timer); tick(); }
        });

        schedule();
    })();
    </script>
@endif
