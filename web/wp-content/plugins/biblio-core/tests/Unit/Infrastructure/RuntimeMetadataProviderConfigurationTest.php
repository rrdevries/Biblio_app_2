<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Infrastructure\Metadata\RuntimeMetadataProviderConfiguration;
use PHPUnit\Framework\TestCase;

final class RuntimeMetadataProviderConfigurationTest extends TestCase
{
    public function testUsesWordPressConstantsWhenTheyAreTheOnlySource(): void
    {
        $configuration = $this->configuration(
            [
                "BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL" => "constant@example.test",
                "GOOGLE_BOOKS_API_KEY" => "constant-google-key",
            ],
            []
        );

        self::assertSame(
            "constant@example.test",
            $configuration->openLibraryContactEmail()
        );
        self::assertSame(
            "constant-google-key",
            $configuration->googleBooksApiKey()
        );
    }

    public function testUsesEnvironmentVariablesWhenConstantsAreAbsent(): void
    {
        $configuration = $this->configuration(
            [],
            [
                "BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL" => "environment@example.test",
                "GOOGLE_BOOKS_API_KEY" => "environment-google-key",
            ]
        );

        self::assertSame(
            "environment@example.test",
            $configuration->openLibraryContactEmail()
        );
        self::assertSame(
            "environment-google-key",
            $configuration->googleBooksApiKey()
        );
    }

    public function testWordPressConstantsTakePrecedenceOverEnvironmentVariables(): void
    {
        $configuration = $this->configuration(
            [
                "BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL" => "constant@example.test",
                "GOOGLE_BOOKS_API_KEY" => "constant-google-key",
            ],
            [
                "BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL" => "environment@example.test",
                "GOOGLE_BOOKS_API_KEY" => "environment-google-key",
            ]
        );

        self::assertSame(
            "constant@example.test",
            $configuration->openLibraryContactEmail()
        );
        self::assertSame(
            "constant-google-key",
            $configuration->googleBooksApiKey()
        );
    }

    public function testMissingConfigurationReturnsNullForBothProviders(): void
    {
        $configuration = $this->configuration([], []);

        self::assertNull($configuration->openLibraryContactEmail());
        self::assertNull($configuration->googleBooksApiKey());
    }

    public function testOpenLibraryCanBeConfiguredIndependently(): void
    {
        $configuration = $this->configuration(
            [],
            ["BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL" => "environment@example.test"]
        );

        self::assertSame(
            "environment@example.test",
            $configuration->openLibraryContactEmail()
        );
        self::assertNull($configuration->googleBooksApiKey());
    }

    public function testGoogleBooksCanBeConfiguredIndependently(): void
    {
        $configuration = $this->configuration(
            [],
            ["GOOGLE_BOOKS_API_KEY" => "environment-google-key"]
        );

        self::assertNull($configuration->openLibraryContactEmail());
        self::assertSame(
            "environment-google-key",
            $configuration->googleBooksApiKey()
        );
    }

    /**
     * @param array<string, mixed> $constants
     * @param array<string, mixed> $environment
     */
    private function configuration(
        array $constants,
        array $environment
    ): RuntimeMetadataProviderConfiguration {
        return new RuntimeMetadataProviderConfiguration(
            static fn (string $name): bool => array_key_exists($name, $constants),
            static fn (string $name): mixed => $constants[$name],
            static fn (string $name): mixed => $environment[$name] ?? false
        );
    }
}
