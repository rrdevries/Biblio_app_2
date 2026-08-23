<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use DateTimeImmutable;
final readonly class PublicReview { public function __construct(private string $displayName, private ?RatingValue $rating, private string $escapedText, private DateTimeImmutable $publishedAt) {} public function displayName(): string { return $this->displayName; } public function rating(): ?RatingValue { return $this->rating; } public function escapedText(): string { return $this->escapedText; } public function publishedAt(): DateTimeImmutable { return $this->publishedAt; } }
