<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Lifecycle;

use Biblio\Core\Exception\FailureReason;

final readonly class WpTransientLifecycleStateStore implements
    LifecycleStateStore
{
    public const HEALTH_TRANSIENT = "biblio_core_schema_health";
    public const FAILURE_TRANSIENT = "biblio_core_lifecycle_failure";
    public const DEFAULT_HEALTH_TTL = 300;
    public const DEFAULT_FAILURE_TTL = 60;

    public function __construct(
        private int $healthTtl = self::DEFAULT_HEALTH_TTL,
        private int $failureTtl = self::DEFAULT_FAILURE_TTL
    ) {
    }

    public function isHealthCurrent(int $expectedVersion): bool
    {
        $state = get_transient(self::HEALTH_TRANSIENT);

        return is_array($state)
            && ($state["version"] ?? null) === $expectedVersion;
    }

    public function rememberHealthy(int $expectedVersion): void
    {
        set_transient(
            self::HEALTH_TRANSIENT,
            ["version" => $expectedVersion],
            $this->healthTtl
        );
        delete_transient(self::FAILURE_TRANSIENT);
    }

    public function failureReason(
        ?int $installedVersion,
        int $expectedVersion
    ): ?FailureReason {
        $state = get_transient(self::FAILURE_TRANSIENT);

        if (
            !is_array($state)
            || !array_key_exists("installed_version", $state)
            || $state["installed_version"] !== $installedVersion
            || ($state["expected_version"] ?? null) !== $expectedVersion
            || !is_string($state["reason"] ?? null)
        ) {
            return null;
        }

        return FailureReason::tryFrom($state["reason"]);
    }

    public function rememberFailure(
        ?int $installedVersion,
        int $expectedVersion,
        FailureReason $reason
    ): void {
        delete_transient(self::HEALTH_TRANSIENT);
        set_transient(
            self::FAILURE_TRANSIENT,
            [
                "installed_version" => $installedVersion,
                "expected_version" => $expectedVersion,
                "reason" => $reason->value,
            ],
            $this->failureTtl
        );
    }

    public function clear(): void
    {
        delete_transient(self::HEALTH_TRANSIENT);
        delete_transient(self::FAILURE_TRANSIENT);
    }
}
