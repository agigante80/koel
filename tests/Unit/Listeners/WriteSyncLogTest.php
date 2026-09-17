<?php

namespace Tests\Unit\Listeners;

use App\Events\MediaScanCompleted;
use App\Listeners\WriteScanLog;
use App\Values\Scanning\ScanResult;
use App\Values\Scanning\ScanResultCollection;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

use function Tests\test_path;

class WriteSyncLogTest extends TestCase
{
    private WriteScanLog $listener;
    private string $originalLogLevel;

    public function setUp(): void
    {
        parent::setUp();

        $this->listener = new WriteScanLog();
        $this->originalLogLevel = config('koel.sync_log_level');
        Carbon::setTestNow(Carbon::create(2021, 1, 2, 12, 34, 56));
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('logs/sync-20210102-123456.log'));
        File::delete(File::glob(storage_path('logs/sync-2020*.log')));
        config(['koel.sync_log_level' => $this->originalLogLevel]);

        parent::tearDown();
    }

    #[Test]
    public function handleWritesNothingWhenThereIsNothingToReport(): void
    {
        config(['koel.sync_log_level' => 'error']);

        $this->listener->handle(new MediaScanCompleted(
            ScanResultCollection::create()
                ->add(ScanResult::success('/media/foo.mp3'))
                ->add(ScanResult::skipped('/media/bar.mp3')),
        ));

        self::assertFileDoesNotExist(storage_path('logs/sync-20210102-123456.log'));
    }

    #[Test]
    public function handlePrunesOlderLogsBeyondTheRetention(): void
    {
        config(['koel.sync_log_level' => 'error', 'koel.sync_log_keep' => 2]);

        foreach (['20200101-000000', '20200102-000000', '20200103-000000'] as $stamp) {
            File::put(storage_path("logs/sync-$stamp.log"), 'old');
        }

        $this->listener->handle(self::createSyncCompleteEvent());

        // The newest two survive: the one just written and the newest of the old ones.
        self::assertFileExists(storage_path('logs/sync-20210102-123456.log'));
        self::assertFileExists(storage_path('logs/sync-20200103-000000.log'));
        self::assertFileDoesNotExist(storage_path('logs/sync-20200102-000000.log'));
        self::assertFileDoesNotExist(storage_path('logs/sync-20200101-000000.log'));
    }

    #[Test]
    public function handleKeepsEveryLogWhenRetentionIsZero(): void
    {
        config(['koel.sync_log_level' => 'error', 'koel.sync_log_keep' => 0]);
        File::put(storage_path('logs/sync-20200101-000000.log'), 'old');

        $this->listener->handle(self::createSyncCompleteEvent());

        self::assertFileExists(storage_path('logs/sync-20200101-000000.log'));
    }

    #[Test]
    public function handleWithLogLevelAll(): void
    {
        config(['koel.sync_log_level' => 'all']);

        $this->listener->handle(self::createSyncCompleteEvent());

        self::assertStringEqualsFile(
            storage_path('logs/sync-20210102-123456.log'),
            File::get(test_path('fixtures/sync-log-all.log')),
        );
    }

    #[Test]
    public function handleWithLogLevelError(): void
    {
        config(['koel.sync_log_level' => 'error']);

        $this->listener->handle(self::createSyncCompleteEvent());

        self::assertStringEqualsFile(
            storage_path('logs/sync-20210102-123456.log'),
            File::get(test_path('fixtures/sync-log-error.log')),
        );
    }

    private static function createSyncCompleteEvent(): MediaScanCompleted
    {
        $resultCollection = ScanResultCollection::create()
            ->add(ScanResult::success('/media/foo.mp3'))
            ->add(ScanResult::error('/media/baz.mp3', 'Something went wrong'))
            ->add(ScanResult::error('/media/qux.mp3', 'Something went horribly wrong'))
            ->add(ScanResult::skipped('/media/bar.mp3'));

        return new MediaScanCompleted($resultCollection);
    }
}
