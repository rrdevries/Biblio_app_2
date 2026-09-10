<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Assessments\Read\OwnAssessmentKind;
use Biblio\Core\Application\Assessments\Read\OwnAssessmentReadRepository;
use Biblio\Core\Application\Assessments\Read\OwnAssessmentView;
use Biblio\Core\Assessments\RatingValue;
use Biblio\Core\Assessments\ReviewContent;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbOwnAssessmentReadRepository implements OwnAssessmentReadRepository
{
    private const FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function notVisibleInLibraryForOwnerAndWork(
        UserId $ownerId,
        LibraryId $libraryId,
        WorkId $workId
    ): array {
        $ratings = $this->tables->ratings();
        $reviews = $this->tables->reviews();
        $publications = $this->tables->contributionPublications();

        $rating = "SELECT 'rating' assessment_type,r.rating_id source_id,"
            . "r.rating_half_units,NULL review_content,r.reading_round_id,"
            . "r.assessed_at,r.updated_at FROM `{$ratings}` r "
            . "WHERE r.user_id=%s AND r.work_id=%s AND NOT EXISTS ("
            . "SELECT 1 FROM `{$publications}` p WHERE p.rating_id=r.rating_id "
            . "AND p.library_id=%s AND p.author_status='active' "
            . "AND p.moderation_status='visible')";
        $review = "SELECT 'review' assessment_type,v.review_id source_id,"
            . "NULL rating_half_units,v.review_content,v.reading_round_id,"
            . "v.assessed_at,v.updated_at FROM `{$reviews}` v "
            . "WHERE v.user_id=%s AND v.work_id=%s AND NOT EXISTS ("
            . "SELECT 1 FROM `{$publications}` p WHERE p.review_id=v.review_id "
            . "AND p.library_id=%s AND p.author_status='active' "
            . "AND p.moderation_status='visible')";
        $sql = "SELECT * FROM ({$rating} UNION ALL {$review}) own_assessments "
            . "ORDER BY updated_at DESC,source_id DESC";
        $rows = $this->database->get_results($this->database->prepare(
            $sql,
            $ownerId->value(),
            $workId->value(),
            $libraryId->value(),
            $ownerId->value(),
            $workId->value(),
            $libraryId->value()
        ));
        if (!is_array($rows) || $this->database->last_error !== "") {
            throw new PersistenceException(
                "Could not read own assessments.",
                0,
                WpdbErrorTranslator::diagnostic(
                    "Own assessment projection",
                    $this->database->last_error
                ),
                FailureReason::PersistenceReadFailed
            );
        }

        try {
            return array_map($this->hydrate(...), $rows);
        } catch (Throwable $exception) {
            if ($exception instanceof PersistenceException) {
                throw $exception;
            }
            throw new PersistenceException(
                "Stored own assessment projection data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function hydrate(object $row): OwnAssessmentView
    {
        $kind = OwnAssessmentKind::from((string) $row->assessment_type);

        return new OwnAssessmentView(
            $kind,
            $kind === OwnAssessmentKind::Rating
                ? RatingValue::fromHalfUnits((int) $row->rating_half_units)
                : null,
            $kind === OwnAssessmentKind::Review
                ? ReviewContent::fromString(
                    (string) $row->review_content
                )->escaped()
                : null,
            $row->reading_round_id !== null,
            $row->assessed_at === null
                ? null
                : $this->date($row->assessed_at)
        );
    }

    private function date(mixed $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::FORMAT,
            (string) $value,
            new DateTimeZone("UTC")
        );
        if ($date === false) {
            throw new PersistenceException(
                "Stored own assessment instant is invalid.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }

        return $date;
    }
}
