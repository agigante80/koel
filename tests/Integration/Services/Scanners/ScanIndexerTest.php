<?php

namespace Tests\Integration\Services\Scanners;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use App\Services\Scanners\ScanIndexer;
use App\Values\Scanning\ScanResult;
use App\Values\Scanning\ScanResultCollection;
use Laravel\Scout\EngineManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\RecordingSearchEngine;
use Tests\TestCase;

class ScanIndexerTest extends TestCase
{
    private RecordingSearchEngine $engine;

    public function setUp(): void
    {
        parent::setUp();

        $engine = new RecordingSearchEngine();
        $this->engine = $engine;
        // static: the Manager rebinds a bound closure to itself, so $this would be the manager.
        $this->app->make(EngineManager::class)->extend('recording', static fn () => $engine);
        config(['scout.driver' => 'recording']);
    }

    #[Test]
    public function indexesTheSavedSongsAndWhatTheyBelongTo(): void
    {
        /** @var Song $saved */
        $saved = Song::factory()->create();
        $saved->syncGenres('Rock');
        /** @var Song $failed */
        $failed = Song::factory()->create();

        // Factories index on save; only what reindex() does from here on counts.
        $this->engine->updated = [];

        (new ScanIndexer())->reindex(ScanResultCollection::create()->add(ScanResult::success($saved->path))->add(ScanResult::error(
            $failed->path,
            'database is locked',
        ))->add(ScanResult::skipped('/media/untouched.mp3')));

        self::assertSame([(string) $saved->id], $this->engine->updatedKeysOf(Song::class));
        self::assertSame([(string) $saved->album_id], $this->engine->updatedKeysOf(Album::class));
        self::assertSame([(string) $saved->artist_id], $this->engine->updatedKeysOf(Artist::class));
        self::assertSame(
            Genre::query()
                ->where('name', 'Rock')
                ->pluck('id')
                ->map(strval(...))
                ->all(),
            $this->engine->updatedKeysOf(Genre::class),
        );
    }

    #[Test]
    public function indexesNothingForAScanThatSavedNothing(): void
    {
        $this->engine->updated = [];

        (new ScanIndexer())->reindex(ScanResultCollection::create()->add(ScanResult::skipped('/media/foo.mp3')));

        self::assertSame([], $this->engine->updated);
    }
}
