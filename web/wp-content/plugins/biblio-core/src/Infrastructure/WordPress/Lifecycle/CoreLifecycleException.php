<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Lifecycle;

use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use RuntimeException;
use Throwable;

final class CoreLifecycleException extends RuntimeException implements
    CoreFailure
{
    public function __construct(
        string $message,
        private readonly FailureReason $failureReason,
        private readonly bool $cached = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): FailureReason
    {
        return $this->failureReason;
    }

    public function isCached(): bool
    {
        return $this->cached;
    }
}
