<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chunked, streaming CSV export (Phase 13).
 *
 * Rows are produced lazily by the caller (typically by chunking a query) so
 * large exports never materialise fully in memory. Authorization must be
 * performed by the caller before this is invoked — this class only streams.
 */
final class CsvExport
{
    /**
     * Stream a CSV download.
     *
     * @param  array<int, string>  $headers
     * @param  Closure():iterable<array<int, scalar|null>>  $rows
     */
    public static function download(string $filename, array $headers, Closure $rows): StreamedResponse
    {
        $headers[] = 'exported_at';

        return Response::streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $headers);

            foreach ($rows() as $row) {
                $row[] = now()->toIso8601String();
                fputcsv($out, array_map(
                    fn ($cell) => is_scalar($cell) || $cell === null ? (string) $cell : json_encode($cell),
                    $row,
                ));
            }

            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
