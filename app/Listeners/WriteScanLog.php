<?php

namespace App\Listeners;

use App\Events\MediaScanCompleted;
use App\Values\Scanning\ScanResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

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
        $keep = (int) config('koel.sync_log_keep', 30);

        if ($keep <= 0) {
            return;
        }

        collect(File::glob(storage_path('logs/sync-*.log')) ?: [])
            ->sort()
            ->reverse()
            ->slice($keep)
            ->each(static fn (string $file) => File::delete($file));
    }
}
