<?php

declare(strict_types=1);

use Biblio\Core\Assessments\AssessmentStale;
use Biblio\Core\Assessments\ContributionDuplicate;
use Biblio\Core\Assessments\ModerationReason;
use Biblio\Core\Assessments\ModerationStatus;
use Biblio\Core\Assessments\PublicationId;
use Biblio\Core\Assessments\PublicationIneligible;
use Biblio\Core\Assessments\PublicationNotAvailable;
use Biblio\Core\Assessments\PublicationStale;
use Biblio\Core\Assessments\PublicationVersion;
use Biblio\Core\Assessments\RatingId;
use Biblio\Core\Assessments\RatingNotAvailable;
use Biblio\Core\Assessments\RatingValue;
use Biblio\Core\Assessments\RatingVersion;
use Biblio\Core\Assessments\ReviewContent;
use Biblio\Core\Assessments\ReviewId;
use Biblio\Core\Assessments\ReviewNotAvailable;
use Biblio\Core\Assessments\ReviewVersion;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingRoundId;

if ($argc !== 7) {
    fwrite(
        STDERR,
        "Expected action, target, argument, actor, ready path and release path.\n"
    );
    exit(2);
}

[
    ,
    $assessmentAction,
    $targetValue,
    $argumentValue,
    $actorValue,
    $readyPath,
    $releasePath,
] = $argv;

require dirname(__DIR__) . '/bootstrap.php';

wp_set_current_user((int) $actorValue);
$application = (new ProductionComposition($wpdb))->application();

if (file_put_contents($readyPath, 'ready') === false) {
    throw new RuntimeException('Could not signal Assessment worker readiness.');
}

$deadline = microtime(true) + 15;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Assessment mutation barrier timed out.');
    }

    usleep(10_000);
}

file_put_contents($readyPath . '-started', 'started');

try {
    if ($assessmentAction === 'create_rating_work') {
        $result = $application->ratingForWorkCreation()->create(
            new WorkId($targetValue),
            RatingValue::fromStars((float) $argumentValue)
        );
        $status = ['status' => 'created', 'id' => $result->id()->value()];
    } elseif ($assessmentAction === 'create_rating_round') {
        $result = $application->ratingForRoundCreation()->create(
            new ReadingRoundId($targetValue),
            RatingValue::fromStars((float) $argumentValue)
        );
        $status = ['status' => 'created', 'id' => $result->id()->value()];
    } elseif ($assessmentAction === 'update_rating') {
        $result = $application->ratingValueUpdate()->update(
            new RatingId($targetValue),
            RatingVersion::initial(),
            RatingValue::fromStars((float) $argumentValue)
        );
        $status = ['status' => 'updated', 'version' => $result->version()->value()];
    } elseif ($assessmentAction === 'update_review') {
        $result = $application->reviewContentUpdate()->update(
            new ReviewId($targetValue),
            ReviewVersion::initial(),
            ReviewContent::fromString($argumentValue)
        );
        $status = ['status' => 'updated', 'version' => $result->version()->value()];
    } elseif ($assessmentAction === 'publish_rating') {
        $result = $application->ratingPublication()->publish(
            new RatingId($targetValue),
            new LibraryId($argumentValue)
        );
        $status = ['status' => 'published', 'id' => $result->id()->value()];
    } elseif ($assessmentAction === 'delete_rating') {
        $application->ratingDeletion()->delete(
            new RatingId($targetValue),
            RatingVersion::initial()
        );
        $status = ['status' => 'deleted'];
    } elseif ($assessmentAction === 'withdraw') {
        $result = $application->publicationWithdrawal()->withdraw(
            new PublicationId($targetValue),
            PublicationVersion::initial()
        );
        $status = ['status' => 'withdrawn', 'version' => $result->version()->value()];
    } elseif ($assessmentAction === 'moderate') {
        $result = $application->publicationModeration()->moderate(
            new PublicationId($targetValue),
            new LibraryId($argumentValue),
            PublicationVersion::initial(),
            ModerationStatus::Hidden,
            new ModerationReason('Concurrent moderation')
        );
        $status = ['status' => 'hidden', 'version' => $result->version()->value()];
    } else {
        throw new RuntimeException('Unknown Assessment worker action.');
    }
} catch (AssessmentStale | PublicationStale) {
    $status = ['status' => 'stale'];
} catch (ContributionDuplicate) {
    $status = ['status' => 'conflict'];
} catch (RatingNotAvailable | ReviewNotAvailable | PublicationNotAvailable) {
    $status = ['status' => 'not_available'];
} catch (PublicationIneligible) {
    $status = ['status' => 'ineligible'];
} catch (Throwable $exception) {
    $status = [
        'status' => 'worker_error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ];
}

fwrite(STDOUT, json_encode($status, JSON_THROW_ON_ERROR) . "\n");
