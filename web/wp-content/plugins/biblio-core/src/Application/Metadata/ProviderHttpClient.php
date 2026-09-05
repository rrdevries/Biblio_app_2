<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

interface ProviderHttpClient
{
    public function get(ProviderHttpRequest $request): ProviderHttpResult;
}
