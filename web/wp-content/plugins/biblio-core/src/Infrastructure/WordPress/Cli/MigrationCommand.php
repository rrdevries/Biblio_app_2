<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Cli;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\Runner\MigrationRunner;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapterRegistry;
use Biblio\Core\Application\Migration\Runner\MigrationSourceMapperRegistry;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogMapper;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationArtifactWriter;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory;
use Biblio\Core\Infrastructure\Migration\MigrationArtifactReceipt;
use Biblio\Core\Infrastructure\WordPress\Migration\RuntimeMigrationEnvironment;
use Biblio\Core\Library\LibraryId;
use Closure;
use InvalidArgumentException;
use Throwable;

final class MigrationCommand
{
    /** @var Closure(CoreApplication): MigrationRunner */
    private readonly Closure $runnerFactory;
    private readonly MigrationCommandOutput $output;
    private readonly FilesystemMigrationArtifactWriter $writer;

    /**
     * @param Closure(): ?CoreApplication $application
     * @param null|Closure(CoreApplication): MigrationRunner $runnerFactory
     */
    public function __construct(
        private readonly Closure $application,
        private readonly string $pluginFile,
        ?Closure $runnerFactory = null,
        ?MigrationCommandOutput $output = null,
        ?FilesystemMigrationArtifactWriter $writer = null
    ) {
        $this->runnerFactory = $runnerFactory
            ?? fn (CoreApplication $core): MigrationRunner => new MigrationRunner(
                new FilesystemMigrationSourcePackageFactory(),
                new MigrationSourceAdapterRegistry([new CurrentV1SourceAdapter()]),
                $core->migrationParticipants(),
                $core->personalMigrationTargets(),
                new RuntimeMigrationEnvironment($this->pluginFile),
                new MigrationSourceMapperRegistry([new CurrentV1CatalogMapper()])
            );
        $this->output = $output ?? new WordPressMigrationCommandOutput();
        $this->writer = $writer ?? new FilesystemMigrationArtifactWriter();
    }

    /**
     * Fingerprint and profile an immutable source package without writes.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     *
     * ## OPTIONS
     *
     * --source-root=<path>
     * : Explicit source package directory.
     *
     * --source-adapter=<id>
     * : Explicit registered adapter and version-validation boundary.
     *
     * [--output-dir=<path>]
     * : Artifact directory; defaults to ignored .local/migration.
     */
    public function profile(array $args, array $assocArgs): void
    {
        unset($args);

        try {
            $sourceRoot = $this->required($assocArgs, "source-root");
            $artifact = $this->runner()->profile(
                $sourceRoot,
                $this->required($assocArgs, "source-adapter")
            );
            $this->success(
                "profile",
                $this->writer->write(
                    $artifact,
                    $this->outputDirectory($assocArgs),
                    $sourceRoot
                )
            );
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Build a deterministic zero-write migration plan.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @subcommand dry-run
     *
     * ## OPTIONS
     *
     * --source-root=<path>
     * : Explicit source package directory.
     *
     * --source-adapter=<id>
     * : Explicit registered adapter and version-validation boundary.
     *
     * --target-user-id=<id>
     * : Explicit active WordPress user ID.
     *
     * --target-library-id=<id>
     * : Explicit designated personal Library ID.
     *
     * [--require-empty]
     * : Fail if the validated target contains any existing content.
     *
     * [--output-dir=<path>]
     * : Artifact directory; defaults to ignored .local/migration.
     */
    public function dry_run(array $args, array $assocArgs): void
    {
        unset($args);

        try {
            $sourceRoot = $this->required($assocArgs, "source-root");
            $artifact = $this->runner()->dryRun(
                $sourceRoot,
                $this->required($assocArgs, "source-adapter"),
                new UserId($this->required($assocArgs, "target-user-id")),
                new LibraryId($this->required($assocArgs, "target-library-id")),
                array_key_exists("require-empty", $assocArgs)
            );
            $this->success(
                "dry_run",
                $this->writer->write(
                    $artifact,
                    $this->outputDirectory($assocArgs),
                    $sourceRoot
                )
            );
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    private function runner(): MigrationRunner
    {
        $application = ($this->application)();
        if (!$application instanceof CoreApplication) {
            throw new \RuntimeException(
                "Biblio Core is not initialized; inspect lifecycle health."
            );
        }
        return ($this->runnerFactory)($application);
    }

    /** @param array<string, mixed> $assocArgs */
    private function required(array $assocArgs, string $key): string
    {
        $value = $assocArgs[$key] ?? null;
        if (!is_string($value) || trim($value) === "") {
            throw new \InvalidArgumentException(
                "Required --{$key} was not supplied."
            );
        }
        return $value;
    }

    /** @param array<string, mixed> $assocArgs */
    private function outputDirectory(array $assocArgs): string
    {
        $value = $assocArgs["output-dir"] ?? null;
        if ($value === null) {
            return dirname($this->pluginFile, 5) . "/.local/migration";
        }
        if (!is_string($value) || trim($value) === "") {
            throw new \InvalidArgumentException(
                "Optional --output-dir must contain a path."
            );
        }
        return $value;
    }

    private function success(string $mode, MigrationArtifactReceipt $receipt): void
    {
        $payload = json_encode([
            "mode" => $mode,
            "zero_write" => true,
            "artifact_path" => $receipt->artifactPath(),
            "artifact_sha256" => $receipt->checksum(),
            "checksum_path" => $receipt->checksumPath(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->output->line($payload);
    }

    private function fail(Throwable $exception): never
    {
        $message = $exception instanceof MigrationRunnerFailure
            ? "Migration {$exception->reason()->value}: {$exception->getMessage()}"
            : ($exception instanceof InvalidArgumentException
                ? "Migration command failed: {$exception->getMessage()}"
                : "Migration command failed without exposing source details.");
        $this->output->error($message);
    }
}
