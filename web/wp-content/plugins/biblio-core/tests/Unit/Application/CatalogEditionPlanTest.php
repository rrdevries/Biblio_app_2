<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Migration\Catalog\CatalogEditionPlan;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class CatalogEditionPlanTest extends TestCase
{
    public function testCanonicalPayloadDistinguishesAllThreeIsbnStates(): void
    {
        $known = new CatalogEditionPlan(
            "work/known",
            "Known ISBN",
            EditionIsbnMetadata::identified(null, new Isbn13("9780306406157"))
        );
        $withoutIsbn = new CatalogEditionPlan(
            "work/without-isbn",
            "Explicitly without ISBN",
            EditionIsbnMetadata::withoutIsbn()
        );
        $unknown = new CatalogEditionPlan(
            "work/unknown",
            "Unknown ISBN",
            EditionIsbnMetadata::unknown()
        );

        self::assertSame("canonical", $known->canonicalPayload()["isbn_state"]);
        self::assertSame(
            "9780306406157",
            $known->canonicalPayload()["isbn_13"]
        );
        self::assertSame(
            "without_isbn",
            $withoutIsbn->canonicalPayload()["isbn_state"]
        );
        self::assertSame("unknown", $unknown->canonicalPayload()["isbn_state"]);
        self::assertNull($unknown->canonicalPayload()["isbn_10"]);
        self::assertNull($unknown->canonicalPayload()["isbn_13"]);
        self::assertNotSame(
            $withoutIsbn->canonicalPayload(),
            $unknown->canonicalPayload()
        );
    }

    public function testInvalidMixedIsbnMetadataStillFailsClosed(): void
    {
        $this->expectException(ValidationException::class);

        EditionIsbnMetadata::identified(
            new Isbn10("0306406152"),
            new Isbn13("9780975229804")
        );
    }

    public function testAliasIsTypedAndCannotCombineWithApprovedTarget(): void
    {
        $alias = new CatalogEditionPlan(
            "work/alias",
            "Alias",
            EditionIsbnMetadata::unknown(),
            aliasOfSourceId: "edition/representative"
        );
        self::assertSame(
            "edition/representative",
            $alias->canonicalPayload()["alias_of_source_id"]
        );

        $this->expectException(ValidationException::class);
        new CatalogEditionPlan(
            "work/alias",
            "Alias",
            EditionIsbnMetadata::unknown(),
            new EditionId("edition-approved"),
            "edition/representative"
        );
    }
}
