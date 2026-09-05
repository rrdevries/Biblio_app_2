<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Metadata;

use Biblio\Core\Application\Metadata\ProviderHttpClient;
use Biblio\Core\Application\Metadata\ProviderHttpRequest;
use Biblio\Core\Application\Metadata\ProviderHttpResponse;
use Biblio\Core\Application\Metadata\ProviderHttpResult;

final readonly class WordPressProviderHttpClient implements ProviderHttpClient
{
    public function get(ProviderHttpRequest $request): ProviderHttpResult
    {
        $response = wp_safe_remote_get(
            $request->url(),
            [
                "headers" => $request->headers(),
                "timeout" => $request->timeoutSeconds(),
                "redirection" => 0,
                "limit_response_size" => $request->maximumResponseBytes(),
            ]
        );

        if (is_wp_error($response)) {
            return ProviderHttpResult::networkFailure();
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 100 || $status > 599) {
            return ProviderHttpResult::networkFailure();
        }

        return ProviderHttpResult::response(
            new ProviderHttpResponse(
                $status,
                wp_remote_retrieve_body($response)
            )
        );
    }
}
