<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\PersonalMigrationTargetInvalid;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Infrastructure\WordPress\Cli\WordPressPersonalUserProvisioner;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use WP_Error;
use WP_User;

final class PersonalMigrationTargetTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];
    private int $previousUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousUserId = get_current_user_id();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        foreach ($this->createdUsers as $userId) {
            $this->database->delete(
                $this->database->usermeta,
                ["user_id" => $userId],
                ["%d"]
            );
            $this->database->delete(
                $this->database->users,
                ["ID" => $userId],
                ["%d"]
            );
        }

        wp_set_current_user($this->previousUserId);
        parent::tearDown();
    }

    public function testExplicitBootstrapCreatesOneNormalPersonalTarget(): void
    {
        $adminId = $this->createUser("identity-admin", "administrator");
        $personalId = $this->createUser("identity-personal", "subscriber");
        wp_set_current_user($adminId);
        $application = (new ProductionComposition($this->database))
            ->application();

        $first = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $personalId)
        );
        $second = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $personalId)
        );

        self::assertSame((string) $personalId, $first->userId()->value());
        self::assertSame(
            $first->libraryId()->value(),
            $second->libraryId()->value()
        );
        self::assertSame("Mijn Bibliotheek", $first->libraryName()->value());
        self::assertTrue($first->readiness()->isClean());
        self::assertSame("empty", $first->readiness()->status());
        self::assertSame(1, $this->countRows(
            $this->tableNames->personalLibraryDesignations()
        ));
        self::assertSame(1, $this->countRows($this->tableNames->libraries()));
        self::assertSame(1, $this->countRows($this->tableNames->memberships()));

        $memberships = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $assignment = $memberships->findFor(
            $first->libraryId(),
            new UserId((string) $personalId)
        );
        self::assertNotNull($assignment);
        self::assertSame(
            MembershipStatus::Active,
            $assignment->membership()->status()
        );
        self::assertSame(
            ManagementRole::Owner,
            $assignment->membership()->managementRole()
        );
        self::assertSame(
            UseAccess::Direct,
            $assignment->membership()->useAccess()
        );
        self::assertNull($memberships->findFor(
            $first->libraryId(),
            new UserId((string) $adminId)
        ));

        $personal = get_userdata($personalId);
        self::assertInstanceOf(WP_User::class, $personal);
        self::assertSame(["subscriber"], $personal->roles);
        self::assertFalse(user_can($personal, "activate_plugins"));
        self::assertFalse(is_super_admin($personalId));

        wp_set_current_user($personalId);
        $context = $application->libraryContexts()->get($first->libraryId());
        self::assertTrue($context->isDesignatedPersonal());
        self::assertTrue($context->capabilities()->canViewCollection());
        self::assertTrue($context->capabilities()->canAddCatalogItem());
        self::assertTrue($context->capabilities()->canManageCatalogItems());
        self::assertTrue($context->capabilities()->canUseItemDirectly());
    }

    public function testPlatformRoleChangesDoNotChangePersonalOwnership(): void
    {
        $personalId = $this->createUser("identity-role", "subscriber");
        $application = (new ProductionComposition($this->database))
            ->application();
        $target = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $personalId)
        );
        $user = get_userdata($personalId);
        self::assertInstanceOf(WP_User::class, $user);

        $user->set_role("administrator");
        $validated = $application->personalMigrationTargets()->validate(
            new UserId((string) $personalId),
            $target->libraryId()
        );

        self::assertSame(
            $target->libraryId()->value(),
            $validated->libraryId()->value()
        );
        self::assertSame(
            ManagementRole::Owner,
            (new WpdbLibraryMembershipRepository(
                $this->database,
                $this->tableNames
            ))->findFor(
                $target->libraryId(),
                new UserId((string) $personalId)
            )?->membership()->managementRole()
        );

        $user->set_role("subscriber");
    }

    public function testTargetValidationRejectsMismatchNonOwnerAndInactiveState(): void
    {
        $ownerA = $this->createUser("identity-a", "subscriber");
        $ownerB = $this->createUser("identity-b", "subscriber");
        $application = (new ProductionComposition($this->database))
            ->application();
        $targetA = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $ownerA)
        );
        $targetB = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $ownerB)
        );

        try {
            $application->personalMigrationTargets()->validate(
                new UserId((string) $ownerA),
                $targetB->libraryId()
            );
            self::fail("Cross-user target mismatch was accepted.");
        } catch (PersonalMigrationTargetInvalid) {
            self::addToAssertionCount(1);
        }

        $this->database->update(
            $this->tableNames->memberships(),
            ["management_role" => "member"],
            [
                "library_id" => $targetA->libraryId()->value(),
                "user_id" => (string) $ownerA,
            ],
            ["%s"],
            ["%s", "%s"]
        );

        try {
            $application->personalMigrationTargets()->validate(
                new UserId((string) $ownerA),
                $targetA->libraryId()
            );
            self::fail("Non-Owner target was accepted.");
        } catch (PersonalMigrationTargetInvalid) {
            self::addToAssertionCount(1);
        }

        $this->database->update(
            $this->tableNames->memberships(),
            ["membership_status" => "inactive", "management_role" => "owner"],
            [
                "library_id" => $targetA->libraryId()->value(),
                "user_id" => (string) $ownerA,
            ],
            ["%s", "%s"],
            ["%s", "%s"]
        );

        try {
            $application->personalMigrationTargets()->validate(
                new UserId((string) $ownerA),
                $targetA->libraryId()
            );
            self::fail("Inactive Owner target was accepted.");
        } catch (PersonalMigrationTargetInvalid) {
            self::addToAssertionCount(1);
        }
    }

    public function testInactiveOrMissingUserFailsClosed(): void
    {
        $personalId = $this->createUser("identity-inactive", "subscriber");
        $application = (new ProductionComposition($this->database))
            ->application();
        $this->database->update(
            $this->database->users,
            ["user_status" => 1],
            ["ID" => $personalId],
            ["%d"],
            ["%d"]
        );

        try {
            $application->personalMigrationTargets()->bootstrap(
                new UserId((string) $personalId)
            );
            self::fail("Inactive user was accepted.");
        } catch (PersonalMigrationTargetInvalid) {
            self::addToAssertionCount(1);
        }

        $this->expectException(PersonalMigrationTargetInvalid::class);
        $application->personalMigrationTargets()->bootstrap(
            new UserId("999999999")
        );
    }

    public function testReadinessReportsTargetOwnedContentWithoutCleanup(): void
    {
        $personalId = $this->createUser("identity-content", "subscriber");
        $application = (new ProductionComposition($this->database))
            ->application();
        $target = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $personalId)
        );
        $this->database->insert(
            $this->tableNames->works(),
            ["work_id" => "identity-work", "work_title" => "Identity Work"]
        );
        $this->database->insert(
            $this->tableNames->editions(),
            [
                "edition_id" => "identity-edition",
                "work_id" => "identity-work",
                "edition_title" => "Identity Edition",
            ]
        );
        $this->database->insert(
            $this->tableNames->items(),
            [
                "item_id" => "identity-item",
                "library_id" => $target->libraryId()->value(),
                "edition_id" => "identity-edition",
                "item_status" => "active",
            ]
        );
        $this->database->insert(
            $this->tableNames->personalReadingTruths(),
            [
                "user_id" => (string) $personalId,
                "work_id" => "identity-work",
                "truth_state" => "unknown",
                "truth_version" => 1,
                "created_at" => "2026-09-09 10:00:00.000000",
                "updated_at" => "2026-09-09 10:00:00.000000",
            ]
        );
        $this->database->insert(
            $this->tableNames->wishlistWorkStates(),
            [
                "user_id" => (string) $personalId,
                "work_id" => "identity-work",
                "target_type" => "work_only",
                "created_at" => "2026-09-10 10:00:00.000000",
                "updated_at" => "2026-09-10 10:00:00.000000",
            ]
        );
        $this->database->insert(
            $this->tableNames->wishlistEntries(),
            [
                "wishlist_entry_id" => "identity-wishlist-entry",
                "user_id" => (string) $personalId,
                "work_id" => "identity-work",
                "target_type" => "work_only",
                "edition_id" => null,
                "created_at" => "2026-09-10 10:00:00.000000",
                "updated_at" => "2026-09-10 10:00:00.000000",
            ]
        );

        $readiness = $application->personalMigrationTargets()->validate(
            new UserId((string) $personalId),
            $target->libraryId()
        )->readiness();

        self::assertFalse($readiness->isClean());
        self::assertSame(
            "non_empty_requires_operator_review",
            $readiness->status()
        );
        self::assertSame(1, $readiness->counts()["library_items"]);
        self::assertSame(
            1,
            $readiness->counts()["user_personal_reading_truths"]
        );
        self::assertSame(1, $readiness->counts()["user_wishlist_entries"]);
        self::assertSame(1, $this->countRows($this->tableNames->items()));
    }

    public function testUserProvisionerIsIdempotentAndRejectsIdentityConflict(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $login = "identity-cli-{$suffix}";
        $email = "{$login}@example.invalid";
        $provisioner = new WordPressPersonalUserProvisioner();

        $created = $provisioner->provision($login, $email, "Renée");
        $this->createdUsers[] = (int) $created->userId()->value();
        $reused = $provisioner->provision($login, $email, "Renée");

        self::assertTrue($created->wasCreated());
        self::assertFalse($reused->wasCreated());
        self::assertSame(
            $created->userId()->value(),
            $reused->userId()->value()
        );
        $user = get_userdata((int) $created->userId()->value());
        self::assertInstanceOf(WP_User::class, $user);
        self::assertSame(["subscriber"], $user->roles);

        $target = (new ProductionComposition($this->database))
            ->application()
            ->personalMigrationTargets()
            ->bootstrap($created->userId());
        self::assertSame($created->userId()->value(), $target->userId()->value());
        self::assertTrue($target->readiness()->isClean());

        $this->expectException(ValidationException::class);
        $provisioner->provision(
            $login,
            "different-{$suffix}@example.invalid",
            "Renée"
        );
    }

    private function createUser(string $prefix, string $role): int
    {
        $suffix = bin2hex(random_bytes(4));
        $result = wp_insert_user([
            "user_login" => "{$prefix}-{$suffix}",
            "user_pass" => "integration-test-only",
            "user_email" => "{$prefix}-{$suffix}@example.invalid",
            "role" => $role,
        ]);

        self::assertFalse($result instanceof WP_Error);
        self::assertIsInt($result);
        $this->createdUsers[] = $result;

        return $result;
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
