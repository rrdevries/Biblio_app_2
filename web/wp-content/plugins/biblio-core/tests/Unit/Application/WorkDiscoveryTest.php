<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Discovery\{WorkDiscoveryCursor,WorkDiscoveryLimit,WorkDiscoveryPage,WorkDiscoveryRepository,WorkDiscoverySearchTerm,WorkDiscoveryService};
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\{AuthenticationException,ValidationException};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class WorkDiscoveryTest extends TestCase
{
    public function testSearchTermAndLimitPreserveExistingBounds(): void
    {
        self::assertSame("Alpha Beta", (new WorkDiscoverySearchTerm(" Alpha\tBeta "))->value());
        self::assertSame(10, (new WorkDiscoveryLimit())->value());
        self::assertSame(25, (new WorkDiscoveryLimit(25))->value());

        foreach (["", "   ", str_repeat("x", 101)] as $invalid) {
            try {
                new WorkDiscoverySearchTerm($invalid);
                self::fail("Invalid Work search was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([0, 26] as $invalid) {
            try {
                new WorkDiscoveryLimit($invalid);
                self::fail("Invalid Work discovery limit was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testServiceRequiresAuthenticationBeforeRepositoryQuery(): void
    {
        $repository = new RecordingWorkDiscoveryRepository();
        $service = new WorkDiscoveryService(
            new ControllableAuthenticatedUser(),
            $repository
        );

        $this->expectException(AuthenticationException::class);
        try {
            $service->search(new WorkDiscoverySearchTerm("Alpha"));
        } finally {
            self::assertSame(0, $repository->calls);
        }
    }

    public function testServiceUsesDefaultLimitAndRejectsMismatchedCursor(): void
    {
        $repository = new RecordingWorkDiscoveryRepository();
        $service = new WorkDiscoveryService(
            new ControllableAuthenticatedUser(new UserId("actor")),
            $repository
        );
        $search = new WorkDiscoverySearchTerm("Alpha");

        self::assertSame([], $service->search($search)->works());
        self::assertSame(10, $repository->limit?->value());
        self::assertSame(1, $repository->calls);

        try {
            $service->search(
                new WorkDiscoverySearchTerm("Beta"),
                cursor: new WorkDiscoveryCursor(
                    $search,
                    "Alpha",
                    new WorkId("work-alpha")
                )
            );
            self::fail("A cursor for another Work search was accepted.");
        } catch (ValidationException) {
            self::assertSame(1, $repository->calls);
        }
    }
}

final class RecordingWorkDiscoveryRepository implements WorkDiscoveryRepository
{
    public int $calls = 0;
    public ?WorkDiscoveryLimit $limit = null;

    public function search(
        WorkDiscoverySearchTerm $search,
        WorkDiscoveryLimit $limit,
        ?WorkDiscoveryCursor $cursor
    ): WorkDiscoveryPage {
        $this->calls++;
        $this->limit = $limit;

        return new WorkDiscoveryPage([], null);
    }
}
