<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\ValidationException;
final readonly class ReviewContent
{
    private function __construct(private string $value) {}
    public static function fromString(string $value): self
    {
        if (preg_match('//u', $value) !== 1) throw new ValidationException("Review must be valid UTF-8.");
        if (str_contains($value, "\0")) throw new ValidationException("Review must not contain NUL.");
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if (mb_strlen($value, 'UTF-8') > 5000) throw new ValidationException("Review must contain at most 5,000 Unicode code points.");
        return new self($value);
    }
    public function value(): string { return $this->value; }
    public function escaped(): string { return htmlspecialchars($this->value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
