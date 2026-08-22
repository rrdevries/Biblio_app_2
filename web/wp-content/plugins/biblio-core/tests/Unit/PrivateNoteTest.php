<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Application\Notes\RenderPrivateNoteContentService;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\WordPress\OpaquePrivateNoteIdGenerator;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use Biblio\Core\Reading\ReadingRoundId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PrivateNoteTest extends TestCase
{
    public function testSafeHtmlSubsetNormalizesAndRoundTripsAtRenderBoundary(): void
    {
        $policy = new StrictPrivateNoteContentPolicy();
        $content = $policy->sanitize(
            "<p>Sterk <strong>boek</strong><br>mooi</p>\r\n"
            . "<blockquote><em>Citaat</em></blockquote>"
            . "<ol><li>Eén</li><li>Twee<ul><li>Sub</li></ul></li></ol>"
        );
        $note = PrivateNote::create(
            new PrivateNoteId("note-1"),
            new UserId("user-1"),
            new WorkId("work-1"),
            null,
            $content,
            new DateTimeImmutable("2026-08-22T10:00:00+00:00")
        );

        self::assertStringNotContainsString("\r", $content->value());
        self::assertSame(
            $content->value(),
            (new RenderPrivateNoteContentService($policy))->render($note)
        );
    }

    public function testEveryForbiddenMarkupClassIsRejectedWithoutStripping(): void
    {
        $policy = new StrictPrivateNoteContentPolicy();
        $forbidden = [
            '<p><script>alert(1)</script></p>',
            '<p onclick="alert(1)">Tekst</p>',
            '<p class="x">Tekst</p>',
            '<p style="color:red">Tekst</p>',
            '<p><a href="javascript:alert(1)">link</a></p>',
            '<p><img src=x onerror=alert(1)>beeld</p>',
            '<iframe src="x"></iframe>',
            '<div>onbekend</div>',
            '<!-- block --><p>tekst</p>',
            '<P>hoofdletters</P>',
            '<p>niet gesloten',
            'uitsluitend plain text',
        ];

        foreach ($forbidden as $html) {
            try {
                $policy->sanitize($html);
                self::fail("Forbidden markup was accepted: {$html}");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEmptyInvalidAndOversizedContentIsRejected(): void
    {
        $policy = new StrictPrivateNoteContentPolicy();

        foreach ([
            '<p> </p>',
            "<p>nul\0byte</p>",
            "<p>\xC3\x28</p>",
            '<p>' . str_repeat('a', StrictPrivateNoteContentPolicy::MAX_BYTES) . '</p>',
        ] as $content) {
            try {
                $policy->sanitize($content);
                self::fail("Invalid content was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAggregateKeepsIdentityOwnerWorkAndCreationTimeImmutable(): void
    {
        $policy = new StrictPrivateNoteContentPolicy();
        $created = new DateTimeImmutable("2026-08-22T10:00:00+00:00");
        $note = PrivateNote::create(
            new PrivateNoteId("note-1"),
            new UserId("user-1"),
            new WorkId("work-1"),
            new ReadingRoundId("round-1"),
            $policy->sanitize('<p>Eerste</p>'),
            $created
        );
        $updated = $note->replaceContent(
            $policy->sanitize('<p>Tweede</p>'),
            new DateTimeImmutable("2026-08-22T11:00:00+00:00")
        )->correctReadingRound(
            null,
            new DateTimeImmutable("2026-08-22T12:00:00+00:00")
        );

        self::assertTrue($updated->id()->equals($note->id()));
        self::assertTrue($updated->userId()->equals($note->userId()));
        self::assertTrue($updated->workId()->equals($note->workId()));
        self::assertSame($created, $updated->createdAt());
        self::assertNull($updated->readingRoundId());
        self::assertSame(3, $updated->version()->value());
    }

    public function testRenderBoundaryRejectsUnsafeContentEvenIfStorageIsCompromised(): void
    {
        $policy = new StrictPrivateNoteContentPolicy();
        $compromised = PrivateNote::create(
            new PrivateNoteId('compromised-note'),
            new UserId('user-1'),
            new WorkId('work-1'),
            null,
            new \Biblio\Core\Notes\PrivateNoteContent(
                '<p onclick="alert(1)">Stored XSS</p>'
            ),
            new DateTimeImmutable('2026-08-22T10:00:00+00:00')
        );

        $this->expectException(ValidationException::class);
        (new RenderPrivateNoteContentService($policy))->render($compromised);
    }

    public function testVersionAndCursorBoundsAreValidated(): void
    {
        self::assertSame(1, PrivateNoteVersion::initial()->value());
        self::assertSame(2, PrivateNoteVersion::initial()->next()->value());

        $this->expectException(ValidationException::class);
        new PrivateNoteVersion(0);
    }

    public function testPrivateNoteIdentityUsesPersistentBoundsAndOpaqueServerFormat(): void
    {
        $generated = (new OpaquePrivateNoteIdGenerator())->next();
        self::assertStringStartsWith('private-note-', $generated->value());
        self::assertSame(45, strlen($generated->value()));
        self::assertSame(191, strlen((new PrivateNoteId(
            str_repeat('n', 191)
        ))->value()));

        $this->expectException(ValidationException::class);
        new PrivateNoteId(str_repeat('n', 192));
    }
}
