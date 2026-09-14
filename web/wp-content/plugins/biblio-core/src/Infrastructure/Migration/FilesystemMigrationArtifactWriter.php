<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Runner\MigrationArtifact;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;

final readonly class FilesystemMigrationArtifactWriter
{
    public function write(
        MigrationArtifact $artifact,
        string $outputDirectory,
        string $sourceRoot
    ): MigrationArtifactReceipt {
        $source = realpath($sourceRoot);
        if (!is_string($source)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Source root disappeared before artifact write."
            );
        }

        $prospectiveOutput = $this->prospectiveRealPath($outputDirectory);
        if ($this->isWithin($prospectiveOutput, $source)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Artifact directory may not be inside the immutable source root."
            );
        }

        if (!is_dir($outputDirectory) && !@mkdir($outputDirectory, 0750, true)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Could not create migration artifact directory."
            );
        }
        $output = realpath($outputDirectory);
        if (!is_string($output) || !is_writable($output)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Migration artifact directory is not writable."
            );
        }
        if ($this->isWithin($output, $source)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Artifact directory may not be inside the immutable source root."
            );
        }

        $suffix = substr($artifact->sourceDigest(), 0, 20);
        if ($artifact->kind() === "dry-run") {
            $target = $artifact->payload()["target"] ?? [];
            $suffix .= "-" . substr(hash("sha256", json_encode($target)), 0, 12);
        }
        $filename = $artifact->kind() . "-{$suffix}.json";
        $artifactPath = $output . DIRECTORY_SEPARATOR . $filename;
        $checksumPath = $artifactPath . ".sha256";
        $json = $artifact->canonicalJson();
        $checksum = hash("sha256", $json);

        $this->atomicWrite($artifactPath, $json);
        $this->atomicWrite($checksumPath, $checksum . "  " . $filename . "\n");

        return new MigrationArtifactReceipt(
            $artifactPath,
            $checksumPath,
            $checksum
        );
    }

    private function prospectiveRealPath(string $path): string
    {
        $current = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : getcwd() . DIRECTORY_SEPARATOR . $path;
        $missing = [];

        while (!file_exists($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::ArtifactWriteFailed,
                    "Could not resolve migration artifact directory."
                );
            }
            $missing[] = basename($current);
            $current = $parent;
        }

        $resolved = realpath($current);
        if (!is_string($resolved)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Could not resolve migration artifact directory."
            );
        }
        foreach (array_reverse($missing) as $segment) {
            if ($segment === "." || $segment === ".." || $segment === "") {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::ArtifactWriteFailed,
                    "Migration artifact directory contains an unsafe path."
                );
            }
            $resolved .= DIRECTORY_SEPARATOR . $segment;
        }

        return $resolved;
    }

    private function isWithin(string $candidate, string $root): bool
    {
        return $candidate === $root
            || str_starts_with(
                $candidate . DIRECTORY_SEPARATOR,
                $root . DIRECTORY_SEPARATOR
            );
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        try {
            $temporary = $path . ".tmp-" . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Could not allocate migration artifact temporary path.",
                $exception
            );
        }

        $written = @file_put_contents($temporary, $bytes, LOCK_EX);
        if ($written !== strlen($bytes) || !@rename($temporary, $path)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::ArtifactWriteFailed,
                "Could not atomically write migration artifact."
            );
        }
    }
}
