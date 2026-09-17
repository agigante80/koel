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
                // MusicBrainzConnector throws on a 5xx or 429, so a rate limit never reaches this
                // line; a 2xx or 404 body without relations is a real miss, remembered as one.
                $relations = $this->connector
                    ->send(new GetReleaseGroupUrlRelationshipsRequest($mbid))
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
