<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSelectorCodec;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchCursorCodec;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchContract;
use Closure;
use LogicException;

final class RestApi
{
    private bool $hooksRegistered = false;
    private readonly RestController $controller;

    /** @param Closure(): ?CoreApplication $applicationProvider */
    public function __construct(Closure $applicationProvider)
    {
        $catalogCursors = new CatalogCursorCodec();
        $historyCursors = new ReadingHistoryCursorCodec();
        $privateNoteCursors = new PrivateNoteCursorCodec();
        $workDiscoveryCursors = new WorkDiscoveryCursorCodec();
        $publicAssessmentCursors = new PublicAssessmentCursorCodec();
        $bibliographicSearch = new RestBibliographicTextSearchContract(
            new BibliographicTextSearchContract(
                new BibliographicSearchCursorCodec(
                    self::bibliographicSearchCursorSecret()
                ),
                new BibliographicAuthorSelectorCodec(
                    self::bibliographicAuthorSelectorSecret()
                )
            )
        );
        $this->controller = new RestController(
            $applicationProvider,
            new RestRequestParser(
                $catalogCursors,
                $historyCursors,
                $privateNoteCursors,
                $workDiscoveryCursors,
                $publicAssessmentCursors,
                null,
                $bibliographicSearch
            ),
            new RestResponseSerializer(
                $catalogCursors,
                $historyCursors,
                $privateNoteCursors,
                $workDiscoveryCursors,
                $publicAssessmentCursors,
                $bibliographicSearch
            ),
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

    private static function bibliographicSearchCursorSecret(): string
    {
        $salt = defined("AUTH_SALT") ? constant("AUTH_SALT") : null;
        if (!is_string($salt) || trim($salt) === "") {
            throw new LogicException("WordPress authentication salt is unavailable.");
        }

        return hash("sha256", $salt . ":bibliographic-text-search-v1");
    }

    private static function bibliographicAuthorSelectorSecret(): string
    {
        $salt = defined("AUTH_SALT") ? constant("AUTH_SALT") : null;
        if (!is_string($salt) || trim($salt) === "") {
            throw new LogicException("WordPress authentication salt is unavailable.");
        }

        return hash("sha256", $salt . ":bibliographic-author-selector-v1");
    }
}
