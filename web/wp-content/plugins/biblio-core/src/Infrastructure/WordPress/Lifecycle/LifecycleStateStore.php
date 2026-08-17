<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Lifecycle;

use Biblio\Core\Exception\FailureReason;

interface LifecycleStateStore
{
    public function isHealthCurrent(int $expectedVersion): bool;

    public function rememberHealthy(int $expectedVersion): void;

    public function failureReason(
        ?int $installedVersion,
        int $expectedVersion
    ): ?FailureReason;

    public function rememberFailure(
        ?int $installedVersion,
        int $expectedVersion,
        FailureReason $reason
    ): void;

    public function clear(): void;
}
