<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSelectorCodec;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkSearchContract;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkSearchCursorCodec;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionSearchContract;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionSearchCursorCodec;
use Biblio\Core\Application\Metadata\Search\BibliographicSearchCursorCodec;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchContract;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSelectorCodec;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicProviderIdentityRepository;
use Closure;
use LogicException;
use wpdb;

final class RestApi
{
    private bool $hooksRegistered = false;
    private readonly RestController $controller;

    /** @param Closure(): ?CoreApplication $applicationProvider */
    public function __construct(
        Closure $applicationProvider,
        ?BibliographicProviderIdentityRepository $providerIdentities = null
    )
    {
        $catalogCursors = new CatalogCursorCodec();
        $historyCursors = new ReadingHistoryCursorCodec();
        $privateNoteCursors = new PrivateNoteCursorCodec();
        $workDiscoveryCursors = new WorkDiscoveryCursorCodec();
        $publicAssessmentCursors = new PublicAssessmentCursorCodec();
        $authorSelectors = new BibliographicAuthorSelectorCodec(
            self::bibliographicAuthorSelectorSecret()
        );
        $workSelectors = new BibliographicWorkSelectorCodec(
            self::bibliographicWorkSelectorSecret(),
            $providerIdentities ?? self::bibliographicProviderIdentities()
        );
        $bibliographicSearch = new RestBibliographicTextSearchContract(
            new BibliographicTextSearchContract(
                new BibliographicSearchCursorCodec(
                    self::bibliographicSearchCursorSecret()
                ),
                $authorSelectors,
                $workSelectors
            )
        );
        $bibliographicAuthorWorks = new RestBibliographicAuthorWorkSearchContract(
            $authorSelectors,
            new BibliographicAuthorWorkSearchContract(
                new BibliographicAuthorWorkSearchCursorCodec(
                    self::bibliographicAuthorWorkSearchCursorSecret()
                ),
                $workSelectors
            )
        );
        $bibliographicWorkEditions = new RestBibliographicWorkEditionSearchContract(
            $workSelectors,
            new BibliographicEditionSearchContract(
                new BibliographicEditionSearchCursorCodec(
                    self::bibliographicEditionSearchCursorSecret()
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
                $bibliographicSearch,
                $bibliographicAuthorWorks,
                $bibliographicWorkEditions
            ),
            new RestResponseSerializer(
                $catalogCursors,
                $historyCursors,
                $privateNoteCursors,
                $workDiscoveryCursors,
                $publicAssessmentCursors,
                $bibliographicSearch,
                $bibliographicAuthorWorks,
                $bibliographicWorkEditions
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

    private static function bibliographicWorkSelectorSecret(): string
    {
        $salt = defined("AUTH_SALT") ? constant("AUTH_SALT") : null;
        if (!is_string($salt) || trim($salt) === "") {
            throw new LogicException("WordPress authentication salt is unavailable.");
        }

        return hash("sha256", $salt . ":bibliographic-work-selector-v1");
    }

    private static function bibliographicProviderIdentities():
        BibliographicProviderIdentityRepository
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            throw new LogicException("WordPress database connection is unavailable.");
        }

        return new WpdbBibliographicProviderIdentityRepository(
            $wpdb,
            new CoreTableNames($wpdb->prefix)
        );
    }

    private static function bibliographicAuthorWorkSearchCursorSecret(): string
    {
        $salt = defined("AUTH_SALT") ? constant("AUTH_SALT") : null;
        if (!is_string($salt) || trim($salt) === "") {
            throw new LogicException("WordPress authentication salt is unavailable.");
        }

        return hash("sha256", $salt . ":bibliographic-author-work-search-v1");
    }

    private static function bibliographicEditionSearchCursorSecret(): string
    {
        $salt = defined("AUTH_SALT") ? constant("AUTH_SALT") : null;
        if (!is_string($salt) || trim($salt) === "") {
            throw new LogicException("WordPress authentication salt is unavailable.");
        }

        return hash("sha256", $salt . ":bibliographic-edition-search-v1");
    }
}
