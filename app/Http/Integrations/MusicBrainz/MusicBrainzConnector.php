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
     * Only a transient failure counts as a failed request. MusicBrainz answers its rate limit
     * with a 503 (and sometimes a 429); those bodies carry no data, and every encyclopedia
     * pipe remembers "nothing found" for a week, so letting them through as an ordinary
     * response cached a rate limit as a miss (or, where a pipe indexed into the body, threw a
     * TypeError). Throwing instead keeps the miss uncached and the next run retries.
     * A 404 for a specific identifier is a real miss and stays an ordinary response.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return $response->serverError() || $response->status() === 429;
    }
}
