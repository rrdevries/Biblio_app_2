<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Throwable;

/** Bounded diagnostic metadata; never serialize throwable arguments or source values. */
final class RehearsalFailureEvidence
{
    /**
     * @param list<string> $phases
     * @return array<string,mixed>
     */
    public static function describe(Throwable $failure, array $phases): array
    {
        $chain = [];
        $current = $failure;
        while ($current !== null && count($chain) < 4) {
            $message = $current instanceof RehearsalFailure ? $current->reason
                : ($current instanceof MigrationRunnerFailure ? $current->reason()->value : null);
            $chain[] = [
                "class" => get_class($current),
                "sanitized_message" => $message ?? "[redacted]",
                "message_sha256" => hash("sha256", $current->getMessage()),
                "origin_file" => basename($current->getFile()),
                "origin_line" => $current->getLine(),
            ];
            $current = $current->getPrevious();
        }
        $applicationFrame = null;
        foreach ($failure->getTrace() as $frame) {
            if (str_starts_with((string) ($frame["class"] ?? ""), "Biblio\\Core\\")) {
                $applicationFrame = [
                    "class" => $frame["class"],
                    "method" => $frame["function"],
                    "file" => basename((string) ($frame["file"] ?? "")),
                    "line" => $frame["line"] ?? null,
                ];
                break;
            }
        }
        return [
            "subphase" => $phases[count($phases) - 1] ?? "unmarked",
            "original" => $chain[0],
            "root" => $chain[count($chain) - 1],
            "cause_chain" => $chain,
            "application_frame" => $applicationFrame,
        ];
    }
}
