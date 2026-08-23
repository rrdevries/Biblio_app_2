<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\CoreApplication;
use Closure;

final class RestApi
{
    private bool $hooksRegistered = false;
    private readonly RestController $controller;

    /** @param Closure(): ?CoreApplication $applicationProvider */
    public function __construct(Closure $applicationProvider)
    {
        $cursors = new CatalogCursorCodec();
        $this->controller = new RestController(
            $applicationProvider,
            new RestRequestParser($cursors),
            new RestResponseSerializer($cursors),
            new RestErrorMapper()
        );
    }

    public function boot(): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        add_action("rest_api_init", [$this, "registerRoutes"]);
        $this->hooksRegistered = true;
    }

    public function registerRoutes(): void
    {
        $this->controller->registerRoutes();
    }
}
