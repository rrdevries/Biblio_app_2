<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Notes\Read\PrivateNoteView;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\NextReading\PreferredReadingSourceType;
use Closure;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController
{
    public const NAMESPACE = "biblio/v1";

    private bool $routesRegistered = false;

    /** @var Closure(): ?CoreApplication */
    private readonly Closure $applicationProvider;

    /** @param Closure(): ?CoreApplication $applicationProvider */
    public function __construct(
        Closure $applicationProvider,
        private readonly RestRequestParser $requests,
        private readonly RestResponseSerializer $responses,
        private readonly RestErrorMapper $errors
    ) {
        $this->applicationProvider = $applicationProvider;
    }

    public function registerRoutes(): void
    {
        if ($this->routesRegistered) {
            return;
        }

        register_rest_route(self::NAMESPACE, "/me/libraries", [
            "methods" => WP_REST_Server::READABLE,
            "callback" => [$this, "libraries"],
            "permission_callback" => [$this, "authenticated"],
        ]);
        register_rest_route(
            self::NAMESPACE,
            "/libraries/(?P<library_id>[^/]+)/items",
            [
                "methods" => WP_REST_Server::READABLE,
                "callback" => [$this, "overview"],
                "permission_callback" => [$this, "authenticated"],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            "/libraries/(?P<library_id>[^/]+)/items/(?P<item_id>[^/]+)",
            [
                "methods" => WP_REST_Server::READABLE,
                "callback" => [$this, "detail"],
                "permission_callback" => [$this, "authenticated"],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            "/libraries/(?P<library_id>[^/]+)/items/"
                . "(?P<item_id>[^/]+)/reading-rounds",
            [
                "methods" => WP_REST_Server::CREATABLE,
                "callback" => [$this, "startReading"],
                "permission_callback" => [$this, "authenticated"],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            "/me/reading-rounds/(?P<reading_round_id>[^/]+)/end",
            [
                "methods" => WP_REST_Server::CREATABLE,
                "callback" => [$this, "endReading"],
                "permission_callback" => [$this, "authenticated"],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            "/me/works/(?P<work_id>[^/]+)/reading-history",
            [
                "methods" => WP_REST_Server::READABLE,
                "callback" => [$this, "readingHistory"],
                "permission_callback" => [$this, "authenticated"],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            "/me/works/(?P<work_id>[^/]+)/private-notes",
            [
                [
                    "methods" => WP_REST_Server::READABLE,
                    "callback" => [$this, "privateNotes"],
                    "permission_callback" => [$this, "authenticated"],
                ],
                [
                    "methods" => WP_REST_Server::CREATABLE,
                    "callback" => [$this, "createPrivateNote"],
                    "permission_callback" => [$this, "authenticated"],
                ],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            "/me/private-notes/(?P<private_note_id>[^/]+)",
            [
                [
                    "methods" => "PATCH",
                    "callback" => [$this, "updatePrivateNote"],
                    "permission_callback" => [$this, "authenticated"],
                ],
                [
                    "methods" => WP_REST_Server::DELETABLE,
                    "callback" => [$this, "deletePrivateNote"],
                    "permission_callback" => [$this, "authenticated"],
                ],
            ]
        );
        register_rest_route(self::NAMESPACE, "/me/next-reading", [
            [
                "methods" => WP_REST_Server::READABLE,
                "callback" => [$this, "nextReadingList"],
                "permission_callback" => [$this, "authenticated"],
            ],
            [
                "methods" => WP_REST_Server::CREATABLE,
                "callback" => [$this, "addNextReading"],
                "permission_callback" => [$this, "authenticated"],
            ],
        ]);
        register_rest_route(
            self::NAMESPACE,
            "/me/next-reading/(?P<entry_id>[^/]+)",
            [
                "methods" => WP_REST_Server::DELETABLE,
                "callback" => [$this, "removeNextReading"],
                "permission_callback" => [$this, "authenticated"],
            ]
        );
        register_rest_route(self::NAMESPACE, "/me/next-reading/undo", [
            "methods" => WP_REST_Server::CREATABLE,
            "callback" => [$this, "undoNextReading"],
            "permission_callback" => [$this, "authenticated"],
        ]);
        register_rest_route(self::NAMESPACE, "/me/next-reading/reorder", [
            "methods" => WP_REST_Server::CREATABLE,
            "callback" => [$this, "reorderNextReading"],
            "permission_callback" => [$this, "authenticated"],
        ]);
        register_rest_route(
            self::NAMESPACE,
            "/me/next-reading/(?P<entry_id>[^/]+)/preferred-source",
            [
                [
                    "methods" => "PATCH",
                    "callback" => [$this, "setNextReadingPreferredSource"],
                    "permission_callback" => [$this, "authenticated"],
                ],
                [
                    "methods" => WP_REST_Server::DELETABLE,
                    "callback" => [$this, "clearNextReadingPreferredSource"],
                    "permission_callback" => [$this, "authenticated"],
                ],
            ]
        );
        register_rest_route(self::NAMESPACE, "/me/works", [
            "methods" => WP_REST_Server::READABLE,
            "callback" => [$this, "discoverWorks"],
            "permission_callback" => [$this, "authenticated"],
        ]);
        register_rest_route(
            self::NAMESPACE,
            "/me/works/(?P<work_id>[^/]+)/preferred-source-options",
            [
                "methods" => WP_REST_Server::READABLE,
                "callback" => [$this, "preferredSourceOptions"],
                "permission_callback" => [$this, "authenticated"],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            "/libraries/(?P<library_id>[^/]+)/works/"
                . "(?P<work_id>[^/]+)/assessments",
            [
                "methods" => WP_REST_Server::READABLE,
                "callback" => [$this, "publicAssessments"],
                "permission_callback" => [$this, "authenticated"],
            ]
        );

        $this->routesRegistered = true;
    }

    public function authenticated(): true|WP_Error
    {
        return is_user_logged_in()
            ? true
            : $this->errors->authenticationRequired();
    }

    public function libraries(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->execute(function (CoreApplication $application): WP_REST_Response {
            return $this->success(
                $this->responses->libraries(
                    $application->libraryContexts()->myLibraries()
                )
            );
        });
    }

    public function overview(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $overview = $application->catalogUiReads()->activeOverview(
                $this->requests->libraryId($request),
                $this->requests->cursor($request),
                $this->requests->pageSize($request)
            );

            return $this->success($this->responses->overview($overview));
        });
    }

    public function detail(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $detail = $application->catalogUiReads()->itemDetail(
                $this->requests->libraryId($request),
                $this->requests->itemId($request)
            );

            return $this->success($this->responses->detail($detail));
        });
    }

    public function startReading(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $round = $application->libraryItemReading()->start(
                $this->requests->libraryId($request),
                $this->requests->itemId($request),
                $this->requests->startedOn($request)
            );

            return $this->success(
                $this->responses->startedReadingRound($round),
                201
            );
        });
    }

    public function endReading(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->endReadingRound($request);
            $round = $input->outcome() === ReadingRoundOutcome::Completed
                ? $application->finishReadingRound()->finish(
                    $input->readingRoundId(),
                    $input->expectedVersion(),
                    $input->finishedOn()
                )
                : $application->stopReadingRound()->stop(
                    $input->readingRoundId(),
                    $input->expectedVersion(),
                    $input->finishedOn()
                );

            return $this->success(
                $this->responses->endedReadingRound($round)
            );
        });
    }

    public function readingHistory(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $this->requests->validateReadingHistoryQuery($request);
            $history = $application->readingHistory()->forWork(
                $this->requests->workId($request),
                $this->requests->readingHistoryCursor($request),
                $this->requests->readingHistoryLimit($request)
            );

            return $this->success(
                $this->responses->readingHistory($history)
            );
        });
    }

    public function publicAssessments(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $libraryId = $this->requests->libraryId($request);
            $workId = $this->requests->workId($request);
            $pageRequest = $this->requests->publicAssessmentPage($request);
            $page = $application->libraryPublicAssessments()->forWork(
                $libraryId,
                $workId,
                $pageRequest["cursor"],
                $pageRequest["page_size"]
            );

            return $this->success($this->responses->publicAssessments(
                $libraryId,
                $workId,
                $page
            ));
        });
    }

    public function privateNotes(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $page = $application->privateNoteViewsForWork()->forWork(
                $this->requests->workId($request),
                $this->requests->privateNotePage($request)
            );

            return $this->success($this->responses->privateNotes($page));
        });
    }

    public function createPrivateNote(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $note = $application->privateNoteCreation()->createForWork(
                $this->requests->workId($request),
                $this->requests->privateNoteContent($request)
            );
            $view = PrivateNoteView::fromPrivateNote(
                $note,
                $application->privateNoteRendering()
            );

            return $this->success($this->responses->privateNote($view), 201);
        });
    }

    public function updatePrivateNote(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->privateNoteUpdate($request);
            $note = $application->privateNoteContentUpdate()->update(
                $input->privateNoteId(),
                $input->expectedVersion(),
                $input->content()
            );
            $view = PrivateNoteView::fromPrivateNote(
                $note,
                $application->privateNoteRendering()
            );

            return $this->success($this->responses->privateNote($view));
        });
    }

    public function deletePrivateNote(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->privateNoteDelete($request);
            $application->privateNoteDeletion()->delete(
                $input->privateNoteId(),
                $input->expectedVersion()
            );

            return new WP_REST_Response(null, 204);
        });
    }

    public function nextReadingList(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ): WP_REST_Response {
            return $this->success($this->responses->nextReadingList(
                $application->myNextReadingList()->get()
            ));
        });
    }

    public function addNextReading(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->nextReadingAdd($request);
            $source = $input["preferred_source"];

            if ($source === null) {
                $application->nextReadingAdd()->add($input["work_id"]);
            } elseif ($source->type() === PreferredReadingSourceType::LibraryItem) {
                $libraryId = $source->libraryId();
                $itemId = $source->itemId();

                if ($libraryId === null || $itemId === null) {
                    throw RestRequestException::invalid("preferred_source");
                }

                $application->nextReadingAdd()->addWithLibraryItem(
                    $input["work_id"],
                    $libraryId,
                    $itemId
                );
            } else {
                $externalLoanId = $source->externalLoanId();

                if ($externalLoanId === null) {
                    throw RestRequestException::invalid("preferred_source");
                }

                $application->nextReadingAdd()->addWithExternalLoan(
                    $input["work_id"],
                    $externalLoanId
                );
            }

            return $this->success($this->responses->nextReadingList(
                $application->myNextReadingList()->get()
            ), 201);
        });
    }

    public function removeNextReading(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->nextReadingRemove($request);
            $removal = $application->nextReadingRemove()->remove(
                $input["entry_id"],
                $input["expected_version"]
            );

            return $this->success($this->responses->nextReadingRemoval(
                $application->myNextReadingList()->get(),
                $removal
            ));
        });
    }

    public function undoNextReading(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $application->nextReadingUndo()->undo(
                $this->requests->nextReadingUndoToken($request)
            );

            return $this->success($this->responses->nextReadingList(
                $application->myNextReadingList()->get()
            ));
        });
    }

    public function reorderNextReading(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->nextReadingReorder($request);
            $application->nextReadingReorder()->reorder(
                $input["expected_version"],
                $input["ordered_entry_ids"]
            );

            return $this->success($this->responses->nextReadingList(
                $application->myNextReadingList()->get()
            ));
        });
    }

    public function setNextReadingPreferredSource(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->nextReadingPreferredSourceMutation($request);
            $source = $input["preferred_source"];

            if ($source->type() === PreferredReadingSourceType::LibraryItem) {
                $libraryId = $source->libraryId();
                $itemId = $source->itemId();

                if ($libraryId === null || $itemId === null) {
                    throw RestRequestException::invalid("preferred_source");
                }

                $application->nextReadingPreferredSource()->setLibraryItem(
                    $input["entry_id"],
                    $input["expected_version"],
                    $libraryId,
                    $itemId
                );
            } else {
                $externalLoanId = $source->externalLoanId();

                if ($externalLoanId === null) {
                    throw RestRequestException::invalid("preferred_source");
                }

                $application->nextReadingPreferredSource()->setExternalLoan(
                    $input["entry_id"],
                    $input["expected_version"],
                    $externalLoanId
                );
            }

            return $this->success($this->responses->nextReadingList(
                $application->myNextReadingList()->get()
            ));
        });
    }

    public function clearNextReadingPreferredSource(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->nextReadingPreferredSourceClear($request);
            $application->nextReadingPreferredSource()->clear(
                $input["entry_id"],
                $input["expected_version"]
            );

            return $this->success($this->responses->nextReadingList(
                $application->myNextReadingList()->get()
            ));
        });
    }

    public function discoverWorks(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $input = $this->requests->nextReadingWorkSearch($request);
            $page = $application->nextReadingDiscovery()->searchWorks(
                $input["search"],
                $input["limit"],
                $input["cursor"]
            );

            return $this->success($this->responses->nextReadingWorks($page));
        });
    }

    public function preferredSourceOptions(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->execute(function (
            CoreApplication $application
        ) use ($request): WP_REST_Response {
            $options = $application->nextReadingDiscovery()->sourceOptions(
                $this->requests->workId($request)
            );

            return $this->success(
                $this->responses->nextReadingSourceOptions($options)
            );
        });
    }

    /** @param Closure(CoreApplication): WP_REST_Response $operation */
    private function execute(Closure $operation): WP_REST_Response|WP_Error
    {
        $application = ($this->applicationProvider)();

        if (!$application instanceof CoreApplication) {
            return $this->errors->coreUnavailable();
        }

        try {
            return $operation($application);
        } catch (RestRequestException $exception) {
            return $this->errors->invalidRequest($exception);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    /** @param array<string, mixed> $data */
    private function success(array $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(["data" => $data], $status);
    }
}
