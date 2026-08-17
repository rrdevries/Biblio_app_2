<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\TransactionException;
use Biblio\Core\Infrastructure\Persistence\TransactionConnection;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionConnectionFake implements TransactionConnection
{
    /** @var list<string> */
    public array $commands = [];
    public bool $beginSucceeds = true;
    public bool $commitSucceeds = true;
    public bool $rollbackSucceeds = true;
    public bool $transactionActive = false;

    public function isTransactionActive(): ?bool
    {
        return $this->transactionActive;
    }

    public function begin(): bool
    {
        $this->commands[] = "begin";

        if ($this->beginSucceeds) {
            $this->transactionActive = true;
        }

        return $this->beginSucceeds;
    }

    public function commit(): bool
    {
        $this->commands[] = "commit";

        if ($this->commitSucceeds) {
            $this->transactionActive = false;
        }

        return $this->commitSucceeds;
    }

    public function rollback(): bool
    {
        $this->commands[] = "rollback";

        if ($this->rollbackSucceeds) {
            $this->transactionActive = false;
        }

        return $this->rollbackSucceeds;
    }

    public function lastError(): string
    {
        return "injected transaction diagnostic";
    }
}

final class WpdbTransactionManagerTest extends TestCase
{
    public function testSuccessfulTransactionCommitsAndReturnsResult(): void
    {
        $connection = new TransactionConnectionFake();
        $manager = new WpdbTransactionManager($connection);

        self::assertSame("result", $manager->run(
            static fn (): string => "result"
        ));
        self::assertSame(["begin", "commit"], $connection->commands);
    }

    public function testOperationFailureRollsBackAndRemainsPrimary(): void
    {
        $connection = new TransactionConnectionFake();
        $manager = new WpdbTransactionManager($connection);
        $operationFailure = new RuntimeException("operation failed");

        try {
            $manager->run(static function () use ($operationFailure): never {
                throw $operationFailure;
            });
            self::fail("Operation failure was swallowed.");
        } catch (RuntimeException $exception) {
            self::assertSame($operationFailure, $exception);
        }

        self::assertSame(["begin", "rollback"], $connection->commands);
    }

    public function testBeginFailureDoesNotInvokeOperation(): void
    {
        $connection = new TransactionConnectionFake();
        $connection->beginSucceeds = false;
        $manager = new WpdbTransactionManager($connection);
        $invoked = false;

        try {
            $manager->run(static function () use (&$invoked): void {
                $invoked = true;
            });
            self::fail("Begin failure was accepted.");
        } catch (TransactionException $exception) {
            self::assertSame(
                FailureReason::TransactionBeginFailed,
                $exception->reason()
            );
            self::assertNotNull($exception->getPrevious());
        }

        self::assertFalse($invoked);
        self::assertSame(["begin"], $connection->commands);
    }

    public function testCommitFailureAttemptsRollbackAndReportsCommit(): void
    {
        $connection = new TransactionConnectionFake();
        $connection->commitSucceeds = false;
        $manager = new WpdbTransactionManager($connection);

        try {
            $manager->run(static fn (): string => "uncommitted");
            self::fail("Commit failure was accepted.");
        } catch (TransactionException $exception) {
            self::assertSame(
                FailureReason::TransactionCommitFailed,
                $exception->reason()
            );
            self::assertNotNull($exception->getPrevious());
        }

        self::assertSame(
            ["begin", "commit", "rollback"],
            $connection->commands
        );
    }

    public function testOperationAndRollbackFailureRetainsBothFailures(): void
    {
        $connection = new TransactionConnectionFake();
        $connection->rollbackSucceeds = false;
        $manager = new WpdbTransactionManager($connection);
        $operationFailure = new RuntimeException("operation failed");

        try {
            $manager->run(static function () use ($operationFailure): never {
                throw $operationFailure;
            });
            self::fail("Rollback failure was swallowed.");
        } catch (TransactionException $exception) {
            self::assertSame(
                FailureReason::TransactionRollbackFailed,
                $exception->reason()
            );
            self::assertSame($operationFailure, $exception->operationFailure());
            self::assertNotNull($exception->rollbackFailure());
            self::assertSame($operationFailure, $exception->getPrevious());
        }

        self::assertSame(["begin", "rollback"], $connection->commands);
    }

    public function testCommitAndRollbackFailureReportsUncertainOutcome(): void
    {
        $connection = new TransactionConnectionFake();
        $connection->commitSucceeds = false;
        $connection->rollbackSucceeds = false;
        $manager = new WpdbTransactionManager($connection);

        try {
            $manager->run(static fn (): null => null);
            self::fail("Commit and rollback failure was swallowed.");
        } catch (TransactionException $exception) {
            self::assertSame(
                FailureReason::TransactionRollbackFailed,
                $exception->reason()
            );
            self::assertInstanceOf(
                TransactionException::class,
                $exception->operationFailure()
            );
            self::assertSame(
                FailureReason::TransactionCommitFailed,
                $exception->operationFailure()?->reason()
            );
            self::assertNotNull($exception->rollbackFailure());
        }

        self::assertSame(
            ["begin", "commit", "rollback"],
            $connection->commands
        );
    }

    public function testNestedTransactionIsRejectedAndOuterRollsBack(): void
    {
        $connection = new TransactionConnectionFake();
        $manager = new WpdbTransactionManager($connection);

        try {
            $manager->run(
                static fn (): mixed => $manager->run(
                    static fn (): null => null
                )
            );
            self::fail("Nested transaction was accepted.");
        } catch (TransactionException $exception) {
            self::assertSame(
                FailureReason::NestedTransactionNotSupported,
                $exception->reason()
            );
        }

        self::assertSame(["begin", "rollback"], $connection->commands);
    }

    public function testExistingConnectionTransactionIsRejected(): void
    {
        $connection = new TransactionConnectionFake();
        $connection->transactionActive = true;
        $manager = new WpdbTransactionManager($connection);

        try {
            $manager->run(static fn (): null => null);
            self::fail("Existing connection transaction was ignored.");
        } catch (TransactionException $exception) {
            self::assertSame(
                FailureReason::NestedTransactionNotSupported,
                $exception->reason()
            );
        }

        self::assertSame([], $connection->commands);
        self::assertTrue($connection->transactionActive);
    }
}
