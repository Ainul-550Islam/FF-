@props(['campaignKey' => null])

{{--
    Phase 20 — newsletter / lead capture signup.
    Public, rate-limited, CSRF-protected. Attribution (source/medium/campaign)
    is attached server-side from the visitor's latest touch.
--}}

<section class="card" aria-labelledby="newsletter-heading" style="margin-top: 24px">
    <h3 id="newsletter-heading" style="margin-top: 0">Get tournament drops first</h3>
    <p class="muted">Free Fire tournament announcements, prize updates and organizer news for Bangladesh. No spam — unsubscribe anytime.</p>

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('marketing.leads.store') }}" class="row" style="gap: 8px; flex-wrap: wrap; align-items: flex-start">
        @csrf
        <input type="hidden" name="type" value="newsletter">
        <div style="flex: 1 1 240px">
            <label for="newsletter-email" class="sr-only">Email address</label>
            <input type="email" id="newsletter-email" name="email" required placeholder="you@example.com"
                   value="{{ old('email') }}" style="width: 100%">
            @error('email') <div class="muted" style="color: var(--danger, #f66); font-size: .8rem">{{ $message }}</div> @enderror
            @error('type') <div class="muted" style="color: var(--danger, #f66); font-size: .8rem">{{ $message }}</div> @enderror
        </div>
        <button type="submit" class="btn btn-cyan btn-sm"
                @if ($campaignKey) onclick="if (window.ffTrack) { ffTrack('player_cta_click', { campaign: @js($campaignKey) }); }"@endif
        >Notify me</button>
    </form>
</section>
