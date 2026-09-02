<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\NextReading\Read\{NextReadingWorkCursor,NextReadingWorkSearchTerm};
use Biblio\Core\Catalog\WorkId;
use Throwable;

final readonly class NextReadingWorkCursorCodec
{
    public function encode(NextReadingWorkCursor $cursor): string
    {
        $json = json_encode([
            "v" => 1,
            "q" => $cursor->search()->value(),
            "title" => $cursor->title(),
            "work_id" => $cursor->workId()->value(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode($json), "+/", "-_"), "=");
    }

    public function decode(string $encoded): NextReadingWorkCursor
    {
        try {
            if ($encoded === "" || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
                throw RestRequestException::invalid("cursor");
            }

            $padding = (4 - strlen($encoded) % 4) % 4;
            $json = base64_decode(
                strtr($encoded, "-_", "+/") . str_repeat("=", $padding),
                true
            );

            if ($json === false) {
                throw RestRequestException::invalid("cursor");
            }

            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

            if (
                !is_array($payload)
                || array_keys($payload) !== ["v", "q", "title", "work_id"]
                || $payload["v"] !== 1
                || !is_string($payload["q"])
                || !is_string($payload["title"])
                || !is_string($payload["work_id"])
            ) {
                throw RestRequestException::invalid("cursor");
            }

            return new NextReadingWorkCursor(
                new NextReadingWorkSearchTerm($payload["q"]),
                $payload["title"],
                new WorkId($payload["work_id"])
            );
        } catch (RestRequestException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw RestRequestException::invalid("cursor");
        }
    }
}
