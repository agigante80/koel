<?php

namespace App\Pipelines\Encyclopedia;

use App\Http\Integrations\MusicBrainz\MusicBrainzConnector;
use App\Http\Integrations\MusicBrainz\Requests\GetReleaseGroupUrlRelationshipsRequest;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class GetAlbumWikidataIdUsingReleaseGroupMbid
{
    use TriesRemember;

    public function __construct(
        private readonly MusicBrainzConnector $connector,
    ) {}

    public function __invoke(?string $mbid, Closure $next): mixed
    {
        if (!$mbid) {
            return $next(null);
        }

        $wikidataId = self::tryRememberForever(
            key: cache_key('album wikidata id from release group mbid', $mbid),
            nothingFoundTtl: now()->addWeek(),
            callback: function () use ($mbid): ?string {
                // A failed request must not be remembered as "nothing found" for a week: a
                // MusicBrainz 503 (its rate limit) or a 404 carries no `relations` at all, and
                // handing that null to Arr::where() was a TypeError on every run. `throw()`
                // surfaces the status instead, and the exception keeps the miss uncached, so the
                // next run tries again. A 2xx body with no relations is a real miss and is cached.
                $relations = $this->connector
                    ->send(new GetReleaseGroupUrlRelationshipsRequest($mbid))
                    ->throw()
                    ->json('relations') ?? [];

                $wikidata = collect(Arr::where(
                    $relations,
                    static fn ($relation) => Arr::get($relation, 'type') === 'wikidata',
                ))->first();

                return $wikidata ? Str::afterLast(Arr::get($wikidata, 'url.resource'), '/') : null;
            },
        );

        return $next($wikidataId);
    }
}
