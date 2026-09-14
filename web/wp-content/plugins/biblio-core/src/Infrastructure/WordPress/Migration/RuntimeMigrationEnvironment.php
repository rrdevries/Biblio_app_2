<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Migration;

use Biblio\Core\Application\Migration\Runner\MigrationBuildProvenance;
use Biblio\Core\Application\Migration\Runner\MigrationEnvironment;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final readonly class RuntimeMigrationEnvironment implements MigrationEnvironment
{
    private string $repositoryRoot;

    public function __construct(private string $pluginFile)
    {
        $this->repositoryRoot = dirname($this->pluginFile, 5);
    }

    public function assertHealthy(): void
    {
        $installed = get_option(CoreSchemaMigrator::VERSION_OPTION, null);
        if ((int) $installed !== CoreSchemaMigrator::CURRENT_VERSION) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnhealthySchema,
                "Biblio Core schema is not at the required healthy version."
            );
        }
    }

    public function provenance(): MigrationBuildProvenance
    {
        $manifest = $this->readManifest();
        $revision = $this->git(["rev-parse", "HEAD"]);
        $status = $this->git(["status", "--porcelain"]);

        return new MigrationBuildProvenance(
            is_string($manifest["version"] ?? null)
                ? $manifest["version"]
                : "unknown",
            CoreSchemaMigrator::CURRENT_VERSION,
            $this->pluginHeaderVersion(),
            is_string($revision) && preg_match('/^[0-9a-f]{40}$/', $revision) === 1
                ? $revision
                : null,
            $status === null || $status !== ""
        );
    }

    /** @return array<string, mixed> */
    private function readManifest(): array
    {
        $bytes = @file_get_contents($this->repositoryRoot . "/manifest.json");
        if (!is_string($bytes)) {
            return [];
        }
        $decoded = json_decode($bytes, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function pluginHeaderVersion(): string
    {
        $bytes = @file_get_contents($this->pluginFile);
        if (
            !is_string($bytes)
            || preg_match('/^ \* Version:\s*([^\r\n]+)$/m', $bytes, $matches) !== 1
        ) {
            return "unknown";
        }
        return trim($matches[1]);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): ?string
    {
        $parts = array_map(escapeshellarg(...), $arguments);
        $command = "git -C " . escapeshellarg($this->repositoryRoot)
            . " " . implode(" ", $parts) . " 2>/dev/null";
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);
        return $exitCode === 0 ? trim(implode("\n", $output)) : null;
    }
}
