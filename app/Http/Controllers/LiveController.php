<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Realtime / live-update endpoints (Phase 12).
 *
 *  - GET  /tournaments/{tournament}/live  → JSON polling (primary transport)
 *  - GET  /tournaments/{tournament}/stream → SSE (optional; requires a
 *         concurrent-capable server for long-lived connections)
 *  - GET  /notifications/unread           → JSON unread badge count (auth)
 *
 * Visibility is enforced server-side by LiveEventService; the client is
 * never trusted to declare what it may see.
 */
class LiveController extends Controller
{
    public function __construct(
        protected LiveEventService $live,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * JSON polling endpoint. `since` is the client cursor (last seen global
     * event id). Returns the current revision plus events newer than `since`
     * that the viewer is allowed to see.
     */
    public function tournamentLive(Tournament $tournament, Request $request)
    {
        $since = max(0, (int) $request->query('since', 0));
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $events = $this->live->since($since, $tournament, $request->user(), $limit);

        return response()->json([
            'revision' => $this->live->latestCursor(),
            'since' => $since,
            'count' => $events->count(),
            'events' => $events->map(fn ($e) => $this->serialize($e))->values(),
        ]);
    }

    /**
     * Legacy `/live/poll` entry point. The canonical polling transport is
     * `tournaments.live`; the old URL keeps working by resolving the
     * tournament from the query string and delegating to the same handler.
     */
    public function poll(Request $request)
    {
        $tournament = Tournament::query()
            ->where('slug', (string) $request->query('tournament'))
            ->orWhere('id', (int) $request->query('tournament'))
            ->firstOrFail();

        return $this->tournamentLive($tournament, $request);
    }

    /**
     * Server-Sent Events stream for a tournament. Bounded lifetime with
     * comment heartbeats; ends cleanly so clients reconnect.
     */
    public function stream(Tournament $tournament, Request $request): StreamedResponse
    {
        $heartbeat = max(1, (int) config('live.stream.heartbeat', 15));
        $maxDuration = max(0, (int) config('live.stream.max_duration', 60));
        $viewer = $request->user();

        return response()->stream(function () use ($tournament, $viewer, $heartbeat, $maxDuration) {
            $startedAt = microtime(true);

            // Clear output buffers so frames are flushed to the client
            // immediately (required by the PHP built-in dev server; harmless
            // behind nginx/apache + php-fpm). Skipped while running tests,
            // where the HTTP kernel captures the stream in its own buffer.
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');

            $cursor = 0;

            while (true) {
                $events = $this->live->since($cursor, $tournament, $viewer, 50);

                foreach ($events as $event) {
                    echo 'id: ' . $event->id . "\n";
                    echo "event: live\n";
                    echo 'data: ' . json_encode($this->serialize($event)) . "\n\n";
                    flush();

                    $cursor = max($cursor, $event->id);
                }

                echo ": heartbeat\n\n";
                flush();

                if ($maxDuration <= 0 || (microtime(true) - $startedAt) >= $maxDuration) {
                    echo "event: live\ndata: {\"closed\":true}\n\n";
                    flush();

                    break;
                }

                sleep($heartbeat);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Unread notification count for the navigation badge (auth only).
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        return response()->json([
            'unread' => $this->notifications->unreadCount($user),
        ]);
    }

    /**
     * A non-sensitive JSON shape for a live event (no actor id, no hidden
     * fields — the actor and any staff metadata stay server-side).
     */
    protected function serialize($event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'payload' => $event->payload ?? [],
            'at' => $event->created_at?->toIso8601String(),
        ];
    }
}
