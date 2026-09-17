<?php

namespace App\Services\Scanners;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use App\Values\Scanning\ScanResult;
use App\Values\Scanning\ScanResultCollection;
use Illuminate\Support\Collection;

/**
 * Puts the songs a parallel scan saved, and their albums, artists and genres, into the search
 * index from ONE process.
 *
 * The scan workers run with search syncing off (see ScanChunkCommand): the default Scout
 * driver is TNTSearch, whose index is a SQLite file with one writer, and four workers saving
 * Searchable models at once collided on it with "database is locked", which then counted a
 * perfectly good file as invalid. Indexing here, after the workers have returned, keeps the
 * writes serial without giving up the parallel tagging.
 */
class ScanIndexer
{
    private const int CHUNK_SIZE = 500;

    public function reindex(ScanResultCollection $results): void
    {
        $results
            ->success()
            ->map(static fn (ScanResult $result): string => $result->path)
            ->chunk(self::CHUNK_SIZE)
            ->each(static function (Collection $paths): void {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Song> $songs */
                $songs = Song::query()->whereIn('path', $paths->all())->get();

                if ($songs->isEmpty()) {
                    return;
                }

                $songs->searchable();
                Album::query()->whereKey($songs->pluck('album_id')->unique()->filter())->get()->searchable();
                Artist::query()->whereKey($songs->pluck('artist_id')->unique()->filter())->get()->searchable();
                Genre::query()->whereKey($songs->pluck('genres.*.id')->flatten()->unique())->get()->searchable();
            });
    }
}
