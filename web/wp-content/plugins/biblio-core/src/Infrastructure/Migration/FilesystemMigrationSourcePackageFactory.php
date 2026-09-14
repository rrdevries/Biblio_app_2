<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourceFile;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackageFactory;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class FilesystemMigrationSourcePackageFactory implements MigrationSourcePackageFactory
{
    public function build(string $sourceRoot): MigrationSourcePackage
    {
        if ($sourceRoot === "" || !file_exists($sourceRoot)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceMissing,
                "Migration source root does not exist."
            );
        }
        if (is_link($sourceRoot) || !is_dir($sourceRoot)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceUnsafe,
                "Migration source root must be a real directory, not a symlink."
            );
        }

        $root = realpath($sourceRoot);
        if (!is_string($root) || !is_readable($root)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceUnreadable,
                "Migration source root is unreadable."
            );
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink()) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceUnsafe,
                    "Migration source package may not contain symlinks."
                );
            }
            if ($entry->isDir()) {
                continue;
            }
            if (!$entry->isFile()) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceUnsafe,
                    "Migration source package contains a non-file entry."
                );
            }

            $relativePath = substr($path, strlen($root) + 1);
            if ($relativePath === "") {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceUnsafe,
                    "Could not derive a safe relative source path."
                );
            }
            $relativePath = str_replace(DIRECTORY_SEPARATOR, "/", $relativePath);

            $before = @lstat($path);
            $permissions = @fileperms($path);
            if (
                !is_array($before)
                || !is_int($permissions)
                || ($permissions & 0444) === 0
                || !is_readable($path)
            ) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceUnreadable,
                    "Migration source file is unreadable: {$relativePath}."
                );
            }

            $hash = @hash_file("sha256", $path);
            $after = @lstat($path);
            if (!is_string($hash)) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceUnreadable,
                    "Could not hash migration source file: {$relativePath}."
                );
            }
            if (
                $before["ino"] !== $after["ino"]
                || $before["size"] !== $after["size"]
                || $before["mtime"] !== $after["mtime"]
            ) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceChanged,
                    "Migration source file changed while it was hashed."
                );
            }

            $files[] = new MigrationSourceFile(
                $relativePath,
                (int) $after["size"],
                $hash
            );
        }

        usort($files, static fn (MigrationSourceFile $a, MigrationSourceFile $b): int =>
            $a->relativePath() <=> $b->relativePath());
        $manifest = array_map(
            static fn (MigrationSourceFile $file): array => $file->toArray(),
            $files
        );

        return new MigrationSourcePackage(
            $root,
            $files,
            hash("sha256", DeterministicJson::encode($manifest))
        );
    }
}
