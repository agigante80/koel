<?php

namespace App\Listeners;

use App\Events\MediaScanCompleted;
use App\Values\Scanning\ScanResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

readonly class WriteScanLog implements ShouldQueue
{
    public function handle(MediaScanCompleted $event): void
    {
        $transformer = static fn (ScanResult $entry) => (string) $entry;

        /** @var Collection $messages */
        $messages = config('koel.sync_log_level') === 'all'
            ? $event->results->map($transformer)
            : $event->results->error()->map($transformer);

        // A scan with nothing to report writes nothing. Scheduled rescans run every hour on
        // some installs, and an empty file per scan is hundreds of empty files a month.
        if ($messages->isEmpty()) {
            return;
        }

        rescue(static function () use ($messages): void {
            $file = storage_path('logs/sync-' . now()->format('Ymd-His') . '.log');
            File::put($file, implode(PHP_EOL, $messages->toArray()));

            self::prune();
        });
    }

    /**
     * Keep only the newest `koel.sync_log_keep` scan logs. Nothing else ever removes them:
     * Laravel's log rotation only covers the files its own channels write.
     */
    private static function prune(): void
    {
        // A blank or non-numeric SYNC_LOG_KEEP must not read as 0, which means "keep everything"
        // and would silently disable pruning; it falls back to the default instead.
        $keep = filter_var(config('koel.sync_log_keep'), FILTER_VALIDATE_INT);

        if ($keep === false) {
            $keep = 30;
        }

        if ($keep <= 0) {
            return;
        }

        // One delete() call with the whole list. Not ->each(File::delete(...)): each() passes
        // the key as a second argument, and Filesystem::delete() reads every argument as a
        // path, so that unlinked the log AND a file named after the key, returned false, and
        // stopped the loop after one file.
        $excess = collect(File::glob(storage_path('logs/sync-*.log')) ?: [])
            ->sort()
            ->reverse()
            ->slice($keep)
            ->values()
            ->all();

        if ($excess && !File::delete($excess)) {
            Log::warning('Could not prune every old scan log under storage/logs; check their ownership.');
        }
    }
}
