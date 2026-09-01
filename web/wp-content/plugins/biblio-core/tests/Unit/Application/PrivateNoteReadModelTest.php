<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Notes\Read\GetMyPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\Read\PrivateNoteView;
use Biblio\Core\Application\Notes\Read\PrivateNoteViewPage;
use Biblio\Core\Application\Notes\RenderPrivateNoteContentService;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteContent;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNotePage;
use Biblio\Core\Notes\PrivateNotePageRequest;
use Biblio\Core\Notes\PrivateNoteRepository;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PrivateNoteReadModelTest extends TestCase
{
    public function testViewPageIsOwnerScopedRenderedAndCursorReady(): void
    {
        $policy = new StrictPrivateNoteContentPolicy();
        $first = $this->note(
            "note-z",
            "<p><strong>Eerste</strong></p>",
            "2026-08-31T10:00:00.200000+00:00",
            $policy
        );
        $last = $this->note(
            "note-a",
            "<blockquote>Tweede</blockquote>",
            "2026-08-31T10:00:00.100000+00:00",
            $policy
        );
        $repository = new RecordingPrivateNoteReadRepository(
            new PrivateNotePage([$first, $last], true)
        );
        $service = new GetMyPrivateNotesForWorkService(
            new ControllableAuthenticatedUser(new UserId("actor-a")),
            $repository,
            new RenderPrivateNoteContentService($policy)
        );

        $page = $service->forWork(new WorkId("work-a"));

        self::assertSame("actor-a", $repository->userId?->value());
        self::assertSame("work-a", $repository->workId?->value());
        self::assertSame(50, $repository->page?->limit());
        self::assertSame(1, $repository->calls);
        self::assertSame([
            "<p><strong>Eerste</strong></p>",
            "<blockquote>Tweede</blockquote>",
        ], array_map(
            static fn (PrivateNoteView $view): string => $view->contentHtml(),
            $page->notes()
        ));
        self::assertSame("note-z", $page->notes()[0]->id()->value());
        self::assertSame(1, $page->notes()[0]->version()->value());
        self::assertSame(
            "2026-08-31 10:00:00.100000",
            $page->nextCursor()?->beforeUpdatedAt()->format("Y-m-d H:i:s.u")
        );
        self::assertSame("note-a", $page->nextCursor()?->beforeId()->value());
    }

    public function testViewContainsOnlyTheAdapterAllowlist(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(PrivateNoteView::class))->getMethods(
                ReflectionMethod::IS_PUBLIC
            )
        );
        sort($methods);

        self::assertSame([
            "contentHtml",
            "fromPrivateNote",
            "id",
            "version",
        ], $methods);
    }

    public function testZeroNotesHasNoContinuation(): void
    {
        $repository = new RecordingPrivateNoteReadRepository(
            new PrivateNotePage([], false)
        );
        $page = (new GetMyPrivateNotesForWorkService(
            new ControllableAuthenticatedUser(new UserId("actor-a")),
            $repository,
            new RenderPrivateNoteContentService(
                new StrictPrivateNoteContentPolicy()
            )
        ))->forWork(
            new WorkId("work-a"),
            new PrivateNotePageRequest(7)
        );

        self::assertSame([], $page->notes());
        self::assertNull($page->nextCursor());
        self::assertSame(7, $repository->page?->limit());
    }

    public function testUnsafeAggregateContentCannotReachTheView(): void
    {
        $compromised = new PrivateNote(
            new PrivateNoteId("unsafe-note"),
            new UserId("actor-a"),
            new WorkId("work-a"),
            null,
            new PrivateNoteContent('<p onclick="alert(1)">Onveilig</p>'),
            new DateTimeImmutable("2026-08-31T10:00:00+00:00"),
            new DateTimeImmutable("2026-08-31T10:00:00+00:00"),
            PrivateNoteVersion::initial()
        );
        $service = new GetMyPrivateNotesForWorkService(
            new ControllableAuthenticatedUser(new UserId("actor-a")),
            new RecordingPrivateNoteReadRepository(
                new PrivateNotePage([$compromised], false)
            ),
            new RenderPrivateNoteContentService(
                new StrictPrivateNoteContentPolicy()
            )
        );

        $this->expectException(ValidationException::class);

        $service->forWork(new WorkId("work-a"));
    }

    public function testAnonymousRequestDoesNotQueryNotes(): void
    {
        $repository = new RecordingPrivateNoteReadRepository(
            new PrivateNotePage([], false)
        );
        $service = new GetMyPrivateNotesForWorkService(
            new ControllableAuthenticatedUser(),
            $repository,
            new RenderPrivateNoteContentService(
                new StrictPrivateNoteContentPolicy()
            )
        );

        try {
            $service->forWork(new WorkId("work-a"));
            self::fail("Anonymous Private Note page was accepted.");
        } catch (AuthenticationException) {
            self::assertSame(0, $repository->calls);
        }
    }

    private function note(
        string $id,
        string $content,
        string $updatedAt,
        StrictPrivateNoteContentPolicy $policy
    ): PrivateNote {
        $createdAt = new DateTimeImmutable("2026-08-31T09:00:00+00:00");

        return new PrivateNote(
            new PrivateNoteId($id),
            new UserId("actor-a"),
            new WorkId("work-a"),
            null,
            $policy->sanitize($content),
            $createdAt,
            new DateTimeImmutable($updatedAt),
            PrivateNoteVersion::initial()
        );
    }
}

final class RecordingPrivateNoteReadRepository implements PrivateNoteRepository
{
    public ?UserId $userId = null;
    public ?WorkId $workId = null;
    public ?PrivateNotePageRequest $page = null;
    public int $calls = 0;

    public function __construct(private PrivateNotePage $result)
    {
    }

    public function findForUser(
        PrivateNoteId $id,
        UserId $userId
    ): ?PrivateNote {
        return null;
    }

    public function findForUserForUpdate(
        PrivateNoteId $id,
        UserId $userId
    ): ?PrivateNote {
        return null;
    }

    public function findPageForUserAndWork(
        UserId $userId,
        WorkId $workId,
        PrivateNotePageRequest $page
    ): PrivateNotePage {
        $this->calls++;
        $this->userId = $userId;
        $this->workId = $workId;
        $this->page = $page;

        return $this->result;
    }

    public function findPageForUserAndReadingRound(
        UserId $userId,
        ReadingRoundId $roundId,
        PrivateNotePageRequest $page
    ): PrivateNotePage {
        return new PrivateNotePage([], false);
    }

    public function findPageForUser(
        UserId $userId,
        PrivateNotePageRequest $page
    ): PrivateNotePage {
        return new PrivateNotePage([], false);
    }
}
