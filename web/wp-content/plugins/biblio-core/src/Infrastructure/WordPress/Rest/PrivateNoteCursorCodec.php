<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Notes\Read\PrivateNoteViewCursor;
use Biblio\Core\Notes\PrivateNoteId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class PrivateNoteCursorCodec
{
    private const VERSION = 1;
    private const MAX_ENCODED_LENGTH = 1024;
    private const TIME_FORMAT = "Y-m-d\\TH:i:s.u\\Z";

    public function encode(PrivateNoteViewCursor $cursor): string
    {
        $json = json_encode(
            [
                "v" => self::VERSION,
                "u" => $cursor->beforeUpdatedAt()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(self::TIME_FORMAT),
                "i" => $cursor->beforeId()->value(),
            ],
            JSON_THROW_ON_ERROR
        );

        return $this->base64UrlEncode($json);
    }

    public function decode(string $encoded): PrivateNoteViewCursor
    {
        try {
            if (
                $encoded === ""
                || strlen($encoded) > self::MAX_ENCODED_LENGTH
                || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1
            ) {
                throw RestRequestException::invalid("cursor");
            }

            $padding = (4 - strlen($encoded) % 4) % 4;
            $json = base64_decode(
                strtr($encoded, "-_", "+/") . str_repeat("=", $padding),
                true
            );

            if ($json === false || $this->base64UrlEncode($json) !== $encoded) {
                throw RestRequestException::invalid("cursor");
            }

            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

            if (
                !is_array($payload)
                || array_keys($payload) !== ["v", "u", "i"]
                || $payload["v"] !== self::VERSION
                || !is_string($payload["u"])
                || !is_string($payload["i"])
            ) {
                throw RestRequestException::invalid("cursor");
            }

            $updatedAt = DateTimeImmutable::createFromFormat(
                "!" . self::TIME_FORMAT,
                $payload["u"],
                new DateTimeZone("UTC")
            );
            $dateErrors = DateTimeImmutable::getLastErrors();

            if (
                !$updatedAt instanceof DateTimeImmutable
                || ($dateErrors !== false
                    && ($dateErrors["warning_count"] !== 0
                        || $dateErrors["error_count"] !== 0))
                || $updatedAt->format(self::TIME_FORMAT) !== $payload["u"]
            ) {
                throw RestRequestException::invalid("cursor");
            }

            return new PrivateNoteViewCursor(
                $updatedAt,
                new PrivateNoteId($payload["i"])
            );
        } catch (RestRequestException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw RestRequestException::invalid("cursor");
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), "+/", "-_"), "=");
    }
}
