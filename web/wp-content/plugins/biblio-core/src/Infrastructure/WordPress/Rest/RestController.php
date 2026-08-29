<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Reading\ReadingRoundOutcome;
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
