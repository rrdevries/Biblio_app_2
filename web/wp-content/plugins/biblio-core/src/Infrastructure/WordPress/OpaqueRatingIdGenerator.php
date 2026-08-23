<?php
declare(strict_types=1);namespace Biblio\Core\Infrastructure\WordPress;use Biblio\Core\Assessments\{RatingId,RatingIdGenerator};final readonly class OpaqueRatingIdGenerator implements RatingIdGenerator{public function next():RatingId{return new RatingId('rating-'.bin2hex(random_bytes(16)));}}
