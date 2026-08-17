<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanWriter;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\WordPress\Identity\WordPressAuthenticatedUser;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Reading\ReadingSourceUnavailable;
use DateTimeImmutable;
use WP_Error;

final class AuthenticatedIdentityBoundaryTest extends PersistenceIntegrationTestCase
{
    public function testWordPressActorMapsToDomainUserAndUnauthenticatedFails(): void
    {
        $wordpressUserId = $this->createWordPressUser("identity-mapping");
        $identity = new WordPressAuthenticatedUser();
        $previousUserId = get_current_user_id();

        try {
            wp_set_current_user($wordpressUserId);

            self::assertSame(
                (string) $wordpressUserId,
                $identity->requireUserId()->value()
            );

            wp_set_current_user(0);

            try {
                $identity->requireUserId();
                self::fail("Unauthenticated identity was accepted.");
            } catch (AuthenticationException $exception) {
                self::assertSame(
                    FailureReason::AuthenticationRequired,
                    $exception->reason()
                );
            }
        } finally {
            wp_set_current_user($previousUserId);
        }
    }

    public function testProductionServicesFollowOnlyCurrentWordPressActor(): void
    {
        $userX = $this->createWordPressUser("production-actor-x");
        $userY = $this->createWordPressUser("production-actor-y");
        $domainUserX = new \Biblio\Core\Identity\UserId((string) $userX);
        $work = new Work(new WorkId("work-identity"), "Identity Work");
        (new WpdbWorkRepository($this->database, $this->tableNames))->add($work);
        $loan = ExternalLoan::active(
            new ExternalLoanId("loan-identity"),
            $domainUserX,
            $work->id(),
            new DateTimeImmutable("2026-08-17T10:00:00.000000+00:00")
        );
        (new WpdbExternalLoanWriter(
            $this->database,
            $this->tableNames
        ))->add($loan);
        $application = (new ProductionComposition($this->database))
            ->application();
        $previousUserId = get_current_user_id();

        try {
            wp_set_current_user($userX);
            self::assertNotNull(
                $application->ownedExternalLoans()->get($loan->id())
            );
            $round = $application->externalLoanReading()->start(
                $loan->id(),
                new DateTimeImmutable("2026-08-17T11:00:00.000000+00:00")
            );
            self::assertSame((string) $userX, $round->userId()->value());

            wp_set_current_user($userY);
            self::assertNull(
                $application->ownedExternalLoans()->get($loan->id())
            );
            self::assertNull(
                $application->ownedReadingRounds()->get($round->id())
            );

            try {
                $application->externalLoanReading()->start(
                    $loan->id(),
                    new DateTimeImmutable(
                        "2026-08-17T12:00:00.000000+00:00"
                    )
                );
                self::fail("A foreign External Loan was used as a source.");
            } catch (ReadingSourceUnavailable $exception) {
                self::assertSame(
                    FailureReason::ReadingSourceUnavailable,
                    $exception->reason()
                );
            }
        } finally {
            wp_set_current_user($previousUserId);
        }
    }

    public function testPersonalLibraryProvisioningCannotTargetAnotherActor(): void
    {
        $userX = $this->createWordPressUser("personal-actor-x");
        $userY = $this->createWordPressUser("personal-actor-y");
        $application = (new ProductionComposition($this->database))
            ->application();
        $previousUserId = get_current_user_id();

        try {
            wp_set_current_user($userX);
            $libraryX = $application->personalLibraries()->ensure();
            wp_set_current_user($userY);
            $libraryY = $application->personalLibraries()->ensure();

            self::assertFalse($libraryX->equals($libraryY));
            self::assertSame(2, $this->tableCount(
                $this->tableNames->personalLibraryDesignations()
            ));
            self::assertSame(1, $this->designationCountFor($userX));
            self::assertSame(1, $this->designationCountFor($userY));
        } finally {
            wp_set_current_user($previousUserId);
        }
    }

    private function createWordPressUser(string $login): int
    {
        $result = wp_insert_user([
            "user_login" => $login,
            "user_pass" => "integration-test-only",
            "user_email" => $login . "@example.invalid",
        ]);

        self::assertFalse($result instanceof WP_Error);
        self::assertIsInt($result);

        return $result;
    }

    private function designationCountFor(int $wordpressUserId): int
    {
        $table = $this->tableNames->personalLibraryDesignations();

        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %s",
            (string) $wordpressUserId
        ));
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
