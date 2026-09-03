<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use Throwable;
use WP_Error;

final readonly class RestErrorMapper
{
    public function authenticationRequired(): WP_Error
    {
        return $this->error(
            "biblio_authentication_required",
            "Authentication is required.",
            401
        );
    }

    public function coreUnavailable(): WP_Error
    {
        return $this->error(
            "biblio_core_unavailable",
            "Biblio is temporarily unavailable.",
            503
        );
    }

    public function invalidRequest(RestRequestException $exception): WP_Error
    {
        return $this->error($exception->errorCode(), $exception->getMessage(), 400);
    }

    public function map(Throwable $exception): WP_Error
    {
        if (!$exception instanceof CoreFailure) {
            error_log(
                "Biblio REST unexpected failure ["
                . $exception::class . "]: " . $exception->getMessage()
            );

            return $this->internalError();
        }

        $reason = $exception->reason();

        if ($reason === FailureReason::AuthenticationRequired) {
            return $this->authenticationRequired();
        }

        if ($reason === FailureReason::ValidationFailed) {
            return $this->error(
                "biblio_validation_failed",
                "The request was rejected by Biblio validation.",
                422
            );
        }

        if (in_array($reason, [
            FailureReason::AuthorizationDenied,
            FailureReason::CatalogItemNotAvailable,
            FailureReason::PrivateNoteNotAvailable,
            FailureReason::ReadingSourceUnavailable,
            FailureReason::ReadingRoundNotAvailable,
            FailureReason::NextReadingWorkUnavailable,
            FailureReason::PreferredReadingSourceUnavailable,
            FailureReason::NextReadingEntryNotAvailable,
            FailureReason::RatingNotAvailable,
            FailureReason::ReviewNotAvailable,
            FailureReason::PublicationNotAvailable,
            FailureReason::ModerationForbidden,
        ], true)) {
            return $this->error(
                "biblio_resource_not_available",
                "The requested resource is not available.",
                404
            );
        }

        if (in_array($reason, [
            FailureReason::ReadingRoundAlreadyActiveForSource,
            FailureReason::ReadingRoundStale,
            FailureReason::CatalogRecordAlreadyExists,
            FailureReason::PrivateNoteStale,
            FailureReason::NextReadingListStale,
            FailureReason::NextReadingUndoUnavailable,
            FailureReason::NextReadingEntryIdCollisionExhausted,
            FailureReason::ContributionDuplicate,
            FailureReason::AssessmentStale,
            FailureReason::PublicationAlreadyActive,
            FailureReason::PublicationStale,
            FailureReason::AssessmentIdCollisionExhausted,
        ], true)) {
            return $this->error(
                "biblio_{$reason->value}",
                "The requested change conflicts with the current state.",
                409
            );
        }

        if ($reason === FailureReason::PublicationIneligible) {
            return $this->error(
                "biblio_publication_ineligible",
                "The requested publication state is not eligible.",
                422
            );
        }

        return $this->internalError();
    }

    private function internalError(): WP_Error
    {
        return $this->error(
            "biblio_internal_error",
            "Biblio could not complete the request.",
            500
        );
    }

    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error($code, $message, ["status" => $status]);
    }
}
