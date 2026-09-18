<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\FinalSourceExportProvenance;
use Biblio\Core\Application\Migration\Cutover\FinalSourceIntakeReceipt;
use Biblio\Core\Application\Migration\Cutover\FinalSourcePackageIdentity;
use Biblio\Core\Application\Migration\Cutover\FinalSourceRetentionMetadata;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapter;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackageFactory;
use ZipArchive;

final readonly class FinalSourceIntakeService
{
    public function __construct(private MigrationSourcePackageFactory $packages)
    {
    }

    public function intake(
        string $archivePath,
        string $expectedArchiveSha256,
        string $expectedManifestSha256,
        string $logicalPackageId,
        string $intakeRoot,
        MigrationSourceAdapter $adapter,
        string $sourceVersion,
        FinalSourceExportProvenance $export,
        FinalSourceRetentionMetadata $retention
    ): FinalSourceIntakeReceipt {
        if (!is_file($archivePath) || is_link($archivePath) || !is_readable($archivePath)) {
            throw $this->unsafe("Final source archive is missing or unsafe.");
        }
        foreach ([$expectedArchiveSha256, $expectedManifestSha256] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw $this->unsafe("Final source expected hash is invalid.");
            }
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,190}$/D', $logicalPackageId) !== 1) {
            throw $this->unsafe("Final source logical package ID is invalid.");
        }
        $archiveHash = @hash_file("sha256", $archivePath);
        if (!is_string($archiveHash) || !hash_equals($expectedArchiveSha256, $archiveHash)) {
            throw $this->changed("Final source archive hash does not match provenance.");
        }
        if (!$adapter->supportsVersion($sourceVersion)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnsupportedVersion,
                "Final source version is not supported by the selected adapter."
            );
        }

        $root = $this->prepareRoot($intakeRoot);
        $packageDirectory = $root . DIRECTORY_SEPARATOR . $logicalPackageId;
        $sourceDirectory = $packageDirectory . DIRECTORY_SEPARATOR . "source";
        if (file_exists($packageDirectory) || is_link($packageDirectory)) {
            throw $this->unsafe("Final source intake package already exists; overwrite is forbidden.");
        }
        if (!@mkdir($sourceDirectory, 0700, true)) {
            throw $this->unsafe("Could not create dedicated final source intake directory.");
        }

        try {
            $this->extract($archivePath, $sourceDirectory);
            $package = $this->packages->build($sourceDirectory);
            if (!hash_equals($expectedManifestSha256, $package->manifestDigest())) {
                throw $this->changed("Final source extracted manifest does not match provenance.");
            }
            $profile = $adapter->profile($package);
            if ($profile->sourceVersion() !== $sourceVersion) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::UnsupportedVersion,
                    "Final source adapter profile version changed during intake."
                );
            }
            $identity = new FinalSourcePackageIdentity(
                $logicalPackageId,
                $archiveHash,
                $package->manifestDigest(),
                $adapter->sourceFamily(),
                $profile->sourceVersion(),
                $adapter->adapterId()
            );
            $this->makeReadOnly($sourceDirectory);
            return new FinalSourceIntakeReceipt(
                $identity,
                $export,
                $retention,
                $package,
                basename($archivePath),
                (int) filesize($archivePath),
                $logicalPackageId . "/source"
            );
        } catch (\Throwable $exception) {
            $this->removeCreatedDirectory($packageDirectory);
            throw $exception;
        }
    }

    private function prepareRoot(string $intakeRoot): string
    {
        if ($intakeRoot === "" || is_link($intakeRoot)) {
            throw $this->unsafe("Final source intake root is unsafe.");
        }
        if (!is_dir($intakeRoot) && !@mkdir($intakeRoot, 0700, true)) {
            throw $this->unsafe("Could not create final source intake root.");
        }
        $root = realpath($intakeRoot);
        if (!is_string($root) || !is_writable($root)) {
            throw $this->unsafe("Final source intake root is not writable.");
        }
        return $root;
    }

    private function extract(string $archivePath, string $destination): void
    {
        $archive = new ZipArchive();
        if ($archive->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw $this->unsafe("Final source archive is not a readable ZIP.");
        }
        $seen = [];
        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
                $name = is_array($stat) ? $stat["name"] : null;
                if (!is_string($name)) {
                    throw $this->unsafe("Final source archive member is unreadable.");
                }
                $directory = str_ends_with($name, "/");
                $normalized = $this->safeMember($name, $directory);
                if (isset($seen[$normalized])) {
                    throw $this->unsafe("Final source archive contains a duplicate path.");
                }
                $seen[$normalized] = true;
                $this->assertSafeMemberType($archive, $index, $directory);
                $target = $destination . DIRECTORY_SEPARATOR
                    . str_replace("/", DIRECTORY_SEPARATOR, $normalized);
                if ($directory) {
                    if (!is_dir($target) && !@mkdir($target, 0700, true)) {
                        throw $this->unsafe("Could not create final source extraction directory.");
                    }
                    continue;
                }
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
                    throw $this->unsafe("Could not create final source extraction parent.");
                }
                $input = $archive->getStream($name);
                $output = @fopen($target, "xb");
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) { fclose($input); }
                    if (is_resource($output)) { fclose($output); }
                    throw $this->unsafe("Could not safely extract final source archive member.");
                }
                $written = stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if (!is_int($written) || $written !== $stat["size"]) {
                    throw $this->unsafe("Could not verify final source archive member extraction.");
                }
            }
        } finally {
            $archive->close();
        }
    }

    private function safeMember(string $name, bool $directory): string
    {
        if (
            $name === ""
            || str_contains($name, "\0")
            || str_contains($name, "\\")
            || str_starts_with($name, "/")
            || preg_match('/^[A-Za-z]:/', $name) === 1
        ) {
            throw $this->unsafe("Final source archive contains an unsafe path.");
        }
        $normalized = $directory ? rtrim($name, "/") : $name;
        $segments = explode("/", $normalized);
        foreach ($segments as $segment) {
            if ($segment === "" || $segment === "." || $segment === "..") {
                throw $this->unsafe("Final source archive contains path traversal.");
            }
        }
        return implode("/", $segments);
    }

    private function assertSafeMemberType(
        ZipArchive $archive,
        int $index,
        bool $directory
    ): void
    {
        $operations = 0;
        $attributes = 0;
        if (!$archive->getExternalAttributesIndex($index, $operations, $attributes)) {
            return;
        }
        $type = ($attributes >> 16) & 0170000;
        if ($type === 0) {
            return;
        }
        $expected = $directory ? 0040000 : 0100000;
        if ($type !== $expected) {
            throw $this->unsafe(
                "Final source archive may not contain symlinks or special files."
            );
        }
    }

    private function makeReadOnly(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!@chmod($entry->getPathname(), $entry->isDir() ? 0500 : 0400)) {
                throw $this->unsafe("Could not make final source extraction immutable.");
            }
        }
        if (!@chmod($directory, 0500) || !@chmod(dirname($directory), 0500)) {
            throw $this->unsafe("Could not make final source extraction immutable.");
        }
    }

    private function removeCreatedDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        @chmod($directory, 0700);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                @chmod($path, 0700);
                @rmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function unsafe(string $message): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(MigrationRunnerReason::SourceUnsafe, $message);
    }

    private function changed(string $message): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(MigrationRunnerReason::SourceChanged, $message);
    }
}
