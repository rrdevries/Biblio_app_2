<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Lifecycle;

use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Throwable;

final readonly class CoreLifecycleCoordinator
{
    public function __construct(
        private CoreSchemaMigrator $schemaMigrator,
        private LifecycleStateStore $stateStore
    ) {
    }

    public function activate(): void
    {
        $this->stateStore->clear();
        $installedVersion = null;

        try {
            $installedVersion = $this->schemaMigrator->installedVersion();
            $this->migrateAndValidate();
        } catch (Throwable $exception) {
            $this->rememberAndThrow(
                $exception,
                $installedVersion,
                $this->schemaMigrator->expectedVersion()
            );
        }
    }

    public function boot(): void
    {
        $expectedVersion = $this->schemaMigrator->expectedVersion();
        $installedVersion = null;

        try {
            $installedVersion = $this->schemaMigrator->installedVersion();
            $cachedFailure = $this->stateStore->failureReason(
                $installedVersion,
                $expectedVersion
            );

            if ($cachedFailure !== null) {
                throw new CoreLifecycleException(
                    "Biblio Core remains unavailable after a recent "
                        . "lifecycle failure; retry is temporarily deferred.",
                    $cachedFailure,
                    true
                );
            }

            if ($installedVersion !== $expectedVersion) {
                $this->migrateAndValidate();

                return;
            }

            if (!$this->stateStore->isHealthCurrent($expectedVersion)) {
                $this->assertHealthy();
                $this->stateStore->rememberHealthy($expectedVersion);
            }
        } catch (CoreLifecycleException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->rememberAndThrow(
                $exception,
                $installedVersion,
                $expectedVersion
            );
        }
    }

    private function migrateAndValidate(): void
    {
        $expectedVersion = $this->schemaMigrator->expectedVersion();

        $this->schemaMigrator->migrate();
        $this->assertHealthy();
        $this->stateStore->rememberHealthy($expectedVersion);
    }

    private function assertHealthy(): void
    {
        $health = $this->schemaMigrator->health();

        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    private function rememberAndThrow(
        Throwable $exception,
        ?int $installedVersion,
        int $expectedVersion
    ): never {
        if ($exception instanceof CoreFailure) {
            $reason = $exception->reason();
            $failure = $exception;
        } else {
            $reason = FailureReason::PersistenceFailure;
            $failure = new CoreLifecycleException(
                "Biblio Core lifecycle failed unexpectedly.",
                $reason,
                previous: $exception
            );
        }

        $this->stateStore->rememberFailure(
            $installedVersion,
            $expectedVersion,
            $reason
        );

        throw $failure;
    }
}
