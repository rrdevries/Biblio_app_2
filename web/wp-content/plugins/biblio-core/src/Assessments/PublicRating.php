<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use DateTimeImmutable;
final readonly class PublicRating { public function __construct(private string $displayName, private RatingValue $rating, private DateTimeImmutable $publishedAt) {} public function displayName(): string { return $this->displayName; } public function rating(): RatingValue { return $this->rating; } public function publishedAt(): DateTimeImmutable { return $this->publishedAt; } }
