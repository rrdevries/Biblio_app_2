<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\ValidationException;
final readonly class ModerationReason
{
    private string $value;
    public function __construct(string $value)
    {
        if (preg_match('//u', $value) !== 1) throw new ValidationException("Moderation reason must be valid UTF-8.");
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        if (trim($normalized) === '' || strlen($normalized) > 65535) throw new ValidationException("Moderation reason must be non-empty and at most 65,535 bytes.");
        $this->value = $normalized;
    }
    public function value(): string { return $this->value; }
}
