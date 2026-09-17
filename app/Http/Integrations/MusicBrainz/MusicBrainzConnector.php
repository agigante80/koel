<?php

namespace App\Http\Integrations\MusicBrainz;

use App\Services\Integrations\MusicBrainzService;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class MusicBrainzConnector extends Connector
{
    use AcceptsJson;
    use AlwaysThrowOnErrors;

    public function resolveBaseUrl(): string
    {
        return config('koel.services.musicbrainz.endpoint');
    }

    /** @inheritdoc */
    public function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => MusicBrainzService::userAgent(),
        ];
    }

    /**
     * Every encyclopedia pipe remembers "nothing found" for a week, so an error body that
     * reaches a pipe as an ordinary response is cached as a miss: a 503 or 429 (the rate
     * limit) for seven days, a 403 (a refused User-Agent) for every artist and album touched,
     * silently, or, where a pipe indexed into the body, a TypeError. Any unsuccessful answer
     * therefore throws, which TriesRemember rescues before its cache write, so the miss stays
     * uncached and the next run retries. The one exception is a 404 for a specific
     * identifier: that is a real miss and stays an ordinary response, remembered as one.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return !$response->successful() && $response->status() !== 404;
    }
}
