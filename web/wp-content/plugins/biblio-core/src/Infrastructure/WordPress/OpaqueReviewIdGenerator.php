<?php
declare(strict_types=1);namespace Biblio\Core\Infrastructure\WordPress;use Biblio\Core\Assessments\{ReviewId,ReviewIdGenerator};final readonly class OpaqueReviewIdGenerator implements ReviewIdGenerator{public function next():ReviewId{return new ReviewId('review-'.bin2hex(random_bytes(16)));}}
