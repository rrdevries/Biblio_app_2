<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Exception\ValidationException;

final readonly class StrictPrivateNoteContentPolicy implements
    PrivateNoteContentPolicy
{
    public const MAX_BYTES = 65_535;

    /** @var array<string, true> */
    private const CONTAINER_TAGS = [
        "p" => true,
        "strong" => true,
        "em" => true,
        "ul" => true,
        "ol" => true,
        "li" => true,
        "blockquote" => true,
    ];

    public function sanitize(string $source): PrivateNoteContent
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $source);

        if (!mb_check_encoding($normalized, "UTF-8")) {
            throw new ValidationException("Private Note content must be valid UTF-8.");
        }

        if (str_contains($normalized, "\0")) {
            throw new ValidationException("Private Note content cannot contain NUL.");
        }

        if (strlen($normalized) > self::MAX_BYTES) {
            throw new ValidationException(
                "Private Note content exceeds the 65,535-byte limit."
            );
        }

        $this->assertStrictSubset($normalized);
        $text = html_entity_decode(
            strip_tags($normalized),
            ENT_QUOTES | ENT_HTML5,
            "UTF-8"
        );
        $visible = preg_replace('/[\s\x{00A0}]+/u', '', $text);

        if ($visible === null || $visible === '') {
            throw new ValidationException(
                "Private Note content must contain visible text."
            );
        }

        return new PrivateNoteContent($normalized);
    }

    private function assertStrictSubset(string $html): void
    {
        $stack = [];
        $offset = 0;
        $hasMarkup = false;

        while (($start = strpos($html, '<', $offset)) !== false) {
            $end = strpos($html, '>', $start + 1);

            if ($end === false) {
                throw new ValidationException("Private Note HTML is malformed.");
            }

            $token = substr($html, $start, $end - $start + 1);
            $hasMarkup = true;

            if ($token === '<br>') {
                $offset = $end + 1;
                continue;
            }

            if (preg_match('/^<([a-z]+)>$/D', $token, $match) === 1) {
                $tag = $match[1];

                if (!isset(self::CONTAINER_TAGS[$tag])) {
                    throw new ValidationException(
                        "Private Note HTML contains a disallowed element."
                    );
                }

                $stack[] = $tag;
                $offset = $end + 1;
                continue;
            }

            if (preg_match('/^<\/([a-z]+)>$/D', $token, $match) === 1) {
                $tag = $match[1];

                if (!isset(self::CONTAINER_TAGS[$tag]) || array_pop($stack) !== $tag) {
                    throw new ValidationException("Private Note HTML is malformed.");
                }

                $offset = $end + 1;
                continue;
            }

            throw new ValidationException(
                "Private Note HTML contains disallowed markup or attributes."
            );
        }

        if (!$hasMarkup) {
            throw new ValidationException(
                "Private Note content must use the safe HTML format."
            );
        }

        if ($stack !== []) {
            throw new ValidationException("Private Note HTML is malformed.");
        }
    }
}
