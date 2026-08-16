<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class InMemoryExternalLoanRepository implements ExternalLoanRepository
{
    private array $loans = [];

    public function add(ExternalLoan $externalLoan): void
    {
        $this->loans[$externalLoan->id()->value()] = $externalLoan;
    }

    public function findForUser(
        ExternalLoanId $externalLoanId,
        UserId $userId
    ): ?ExternalLoan {
        $loan = $this->loans[$externalLoanId->value()] ?? null;

        if ($loan === null || !$userId->equals($loan->userId())) {
            return null;
        }

        return $loan;
    }
}

final class GetOwnedExternalLoanServiceTest extends TestCase
{
    public function testOnlyOwnerCanReadLoanWithoutLibraryContext(): void
    {
        $repository = new InMemoryExternalLoanRepository();
        $loan = $this->loan(new UserId("user-x"));
        $repository->add($loan);
        $service = new GetOwnedExternalLoanService($repository);

        self::assertSame(
            $loan,
            $service->get(new UserId("user-x"), $loan->id())
        );
        self::assertNull(
            $service->get(new UserId("user-y"), $loan->id())
        );
    }

    public function testForeignLoanFromFaultyAdapterIsRejected(): void
    {
        $loan = $this->loan(new UserId("user-x"));
        $faultyRepository = new class($loan) implements ExternalLoanRepository {
            public function __construct(private ExternalLoan $loan)
            {
            }

            public function add(ExternalLoan $externalLoan): void
            {
            }

            public function findForUser(
                ExternalLoanId $externalLoanId,
                UserId $userId
            ): ?ExternalLoan {
                return $this->loan;
            }
        };
        $service = new GetOwnedExternalLoanService($faultyRepository);

        self::assertNull(
            $service->get(new UserId("user-y"), $loan->id())
        );
    }

    private function loan(UserId $userId): ExternalLoan
    {
        return ExternalLoan::active(
            new ExternalLoanId("loan-x"),
            $userId,
            new WorkId("work-w"),
            new DateTimeImmutable(
                "2026-08-10 09:30:00.000000",
                new DateTimeZone("UTC")
            )
        );
    }
}
