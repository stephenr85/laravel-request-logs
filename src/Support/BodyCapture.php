<?php

namespace Rushing\RequestLogs\Support;

/**
 * Decides what a captured request/response body may become inside a log payload.
 *
 * The completion event is queued, so everything a middleware puts on RequestLogData
 * is `json_encode`d by the queue serializer. `json_encode` throws on malformed UTF-8,
 * which means a PDF, an image, a zip — any binary body — kills the request AFTER the
 * response was already produced. The middleware used to decide from the wrong signal:
 * "is the Content-Type application/json?", with every other content type assumed to be
 * loggable text. The right signal is whether the body is TEXT at all.
 *
 * Same shape as the middleware's uploaded-file handling on the request axis: a binary
 * body has no business in a log, so a name/mime/size descriptor replaces it.
 */
class BodyCapture
{
    /**
     * Content types whose bodies are text even though they are not `text/*`.
     */
    protected static array $textualTypes = [
        'application/json',
        'application/xml',
        'application/javascript',
        'application/ecmascript',
        'application/x-www-form-urlencoded',
        'application/graphql',
        'application/x-ndjson',
        'application/yaml',
        'application/x-yaml',
        'application/sql',
    ];

    /**
     * Reduce a raw body string to something a JSON-encodable log payload can hold.
     *
     * @return array<string, mixed>|string
     */
    public static function capture(string $body, string $contentType): mixed
    {
        if (! static::isTextual($contentType) || ! mb_check_encoding($body, 'UTF-8')) {
            return static::describe($contentType, strlen($body));
        }

        if (str_contains($contentType, 'json')) {
            $decoded = json_decode($body, true);

            return $decoded !== null ? $decoded : $body;
        }

        return $body;
    }

    /**
     * Is this content type's body text?
     *
     * An ABSENT content type is treated as text: it is the common case for a plain
     * string response, and `capture()` still runs the UTF-8 check behind this, which
     * is what actually catches binary bytes that arrive undeclared.
     */
    public static function isTextual(string $contentType): bool
    {
        $type = trim(strtolower(strtok($contentType, ';') ?: ''));

        if ($type === '') {
            return true;
        }

        if (str_starts_with($type, 'text/')) {
            return true;
        }

        if (str_ends_with($type, '+json') || str_ends_with($type, '+xml')) {
            return true;
        }

        return in_array($type, static::$textualTypes, true);
    }

    /**
     * The stand-in a non-text body is logged as.
     *
     * @return array<string, mixed>
     */
    public static function describe(string $contentType, ?int $size): array
    {
        return [
            '__binary__' => true,
            'mime' => trim(strtok($contentType, ';') ?: '') ?: null,
            'size' => $size,
        ];
    }

    /**
     * Scrub any non-UTF-8 string out of an already-decoded body array. A latin-1 form
     * field crashes the queue serializer exactly like a PDF does.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function scrub(array $body): array
    {
        array_walk_recursive($body, function (&$value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $value = static::describe('', strlen($value));
            }
        });

        return $body;
    }
}
