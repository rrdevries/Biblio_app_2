<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCreditKey,
    AuthorContributorCreditSourceIdentity
};
use Biblio\Core\Catalog\{
    Author,
    AuthorDisplayNameStatus,
    AuthorId,
    AuthorIdentityStatus,
    ContributorPosition,
    ContributorRole,
    WorkId
};
use PHPUnit\Framework\TestCase;

final class AuthorIdentityFoundationTest extends TestCase
{
    public function testAuthorDefaultsAreConservativeAndStatusesAreOrthogonal(): void
    {
        $provisional = new Author(new AuthorId("author-1"), "Peter King");
        $resolvedObserved = new Author(
            new AuthorId("author-2"),
            "Peter King",
            AuthorIdentityStatus::Resolved,
            AuthorDisplayNameStatus::Observed
        );

        self::assertSame(
            AuthorIdentityStatus::Provisional,
            $provisional->identityStatus()
        );
        self::assertSame(
            AuthorDisplayNameStatus::Observed,
            $provisional->displayNameStatus()
        );
        self::assertSame(1, $provisional->version()->value());
        self::assertSame(
            AuthorIdentityStatus::Resolved,
            $resolvedObserved->identityStatus()
        );
        self::assertSame(
            AuthorDisplayNameStatus::Observed,
            $resolvedObserved->displayNameStatus()
        );
    }

    public function testCreditKeyNormalizesOnlyWhitespaceAndScopesSourceExactly(): void
    {
        $work = new WorkId("work-1");
        $position = new ContributorPosition(1);
        $source = AuthorContributorCreditSourceIdentity::provider(
            "open_library",
            "work",
            "/works/OL1W",
            1
        );
        $first = AuthorContributorCreditKey::fromSource(
            $work,
            ContributorRole::Author,
            $position,
            "  É.\u{00A0}\tSample  ",
            $source
        );
        $whitespaceReplay = AuthorContributorCreditKey::fromSource(
            $work,
            ContributorRole::Author,
            $position,
            "É. Sample",
            $source
        );

        self::assertSame($first->value(), $whitespaceReplay->value());
        self::assertSame(
            $first->normalizedNameHash(),
            $whitespaceReplay->normalizedNameHash()
        );
        self::assertNotSame(
            $first->value(),
            AuthorContributorCreditKey::fromSource(
                $work,
                ContributorRole::Author,
                $position,
                "é. Sample",
                $source
            )->value()
        );
        self::assertNotSame(
            $first->value(),
            AuthorContributorCreditKey::fromSource(
                $work,
                ContributorRole::Author,
                $position,
                "É Sample",
                $source
            )->value()
        );
        self::assertNotSame(
            $first->value(),
            AuthorContributorCreditKey::fromSource(
                $work,
                ContributorRole::Author,
                $position,
                "É. Sample",
                AuthorContributorCreditSourceIdentity::provider(
                    "open_library",
                    "work",
                    "/works/OL2W",
                    1
                )
            )->value()
        );
        self::assertNotSame(
            $first->value(),
            AuthorContributorCreditKey::fromSource(
                new WorkId("work-2"),
                ContributorRole::Author,
                $position,
                "É. Sample",
                $source
            )->value()
        );
        self::assertNotSame(
            $first->value(),
            AuthorContributorCreditKey::fromSource(
                $work,
                ContributorRole::Author,
                new ContributorPosition(2),
                "É. Sample",
                $source
            )->value()
        );
        self::assertNotSame(
            $first->value(),
            AuthorContributorCreditKey::fromSource(
                $work,
                ContributorRole::CoAuthor,
                $position,
                "É. Sample",
                $source
            )->value()
        );
    }
}
