<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Reading\History\ReadingHistoryCursor;
use Biblio\Core\Reading\ReadingRoundId;
use Throwable;

final readonly class ReadingHistoryCursorCodec
{
    public function encode(ReadingHistoryCursor $cursor): string
    {
        $json = json_encode(
            [
                "v" => 1,
                "fe" => $cursor->finishedEarliest(),
                "fl" => $cursor->finishedLatest(),
                "t" => $cursor->tieBreaker()->value(),
            ],
            JSON_THROW_ON_ERROR
        );

        return rtrim(strtr(base64_encode($json), "+/", "-_"), "=");
    }

    public function decode(string $encoded): ReadingHistoryCursor
    {
        try {
            if (
                $encoded === ""
                || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1
            ) {
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
                || array_keys($payload) !== ["v", "fe", "fl", "t"]
                || $payload["v"] !== 1
                || !is_int($payload["fe"])
                || !is_int($payload["fl"])
                || !is_string($payload["t"])
            ) {
                throw RestRequestException::invalid("cursor");
            }

            return new ReadingHistoryCursor(
                $payload["fe"],
                $payload["fl"],
                new ReadingRoundId($payload["t"])
            );
        } catch (RestRequestException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw RestRequestException::invalid("cursor");
        }
    }
}
