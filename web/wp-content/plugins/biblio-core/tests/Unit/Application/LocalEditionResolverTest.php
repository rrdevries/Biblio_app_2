<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\LocalEditionResolutionType;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Catalog\BibliographicMetadataRepository;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIdentifierClaimRepository;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Catalog\WorkId;
use PHPUnit\Framework\TestCase;

final class LocalEditionResolverTest extends TestCase
{
    public function testCanonicalIsbn13AndIsbn10ResolveSameClaimedEdition(): void
    {
        $edition = new Edition(
            new EditionId("edition-existing"),
            new WorkId("work-preserved"),
            EditionIsbnMetadata::identified(
                new Isbn10("0306406152"),
                new Isbn13("9780306406157")
            )
        );
        $claims = $this->createMock(EditionIdentifierClaimRepository::class);
        $claims->expects(self::exactly(2))
            ->method("findByCanonicalIsbn13")
            ->willReturnCallback(static function (Isbn13 $isbn): EditionId {
                self::assertSame("9780306406157", $isbn->value());
                return new EditionId("edition-existing");
            });
        $editions = $this->createMock(EditionRepository::class);
        $editions->expects(self::exactly(2))->method("find")->willReturn($edition);
        $legacy = $this->createMock(BibliographicMetadataRepository::class);
        $legacy->expects(self::never())->method("editionsForIsbns");
        $resolver = new LocalEditionResolver(
            new IsbnCanonicalizer(),
            $claims,
            $editions,
            $legacy
        );

        $by13 = $resolver->resolveInput("9780306406157");
        $by10 = $resolver->resolveInput("0-306-40615-2");

        self::assertSame(LocalEditionResolutionType::LocalExact, $by13->type());
        self::assertSame(LocalEditionResolutionType::LocalExact, $by10->type());
        self::assertSame("work-preserved", $by13->requireEdition()->workId()->value());
        self::assertSame(
            $by13->requireEdition()->id()->value(),
            $by10->requireEdition()->id()->value()
        );
    }

    public function testNoClaimAndNoLegacyIdentifierIsLocalNone(): void
    {
        $claims = $this->createMock(EditionIdentifierClaimRepository::class);
        $claims->method("findByCanonicalIsbn13")->willReturn(null);
        $editions = $this->createMock(EditionRepository::class);
        $legacy = $this->createMock(BibliographicMetadataRepository::class);
        $legacy->method("editionsForIsbns")->willReturn([
            "9780306406157" => [],
            "0306406152" => [],
        ]);
        $resolver = new LocalEditionResolver(
            new IsbnCanonicalizer(),
            $claims,
            $editions,
            $legacy
        );

        $result = $resolver->resolveInput("9780306406157");

        self::assertSame(LocalEditionResolutionType::LocalNone, $result->type());
        self::assertSame([], $result->editions());
    }

    public function testMultipleLegacyAliasesAreTypedAmbiguous(): void
    {
        $first = new Edition(new EditionId("edition-a"), new WorkId("work-a"));
        $second = new Edition(new EditionId("edition-b"), new WorkId("work-b"));
        $claims = $this->createMock(EditionIdentifierClaimRepository::class);
        $claims->method("findByCanonicalIsbn13")->willReturn(null);
        $editions = $this->createMock(EditionRepository::class);
        $legacy = $this->createMock(BibliographicMetadataRepository::class);
        $legacy->method("editionsForIsbns")->willReturn([
            "9780306406157" => [$first],
            "0306406152" => [$second],
        ]);
        $resolver = new LocalEditionResolver(
            new IsbnCanonicalizer(),
            $claims,
            $editions,
            $legacy
        );

        $result = $resolver->resolveInput("0306406152");

        self::assertSame(
            LocalEditionResolutionType::LocalAmbiguous,
            $result->type()
        );
        self::assertSame(
            ["edition-a", "edition-b"],
            array_map(
                static fn (Edition $edition): string => $edition->id()->value(),
                $result->editions()
            )
        );
    }
}
