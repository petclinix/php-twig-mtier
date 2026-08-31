<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class HttpResponse
{
    /** @param array<string, string> $headers lower-cased header name => value */
    private function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /** @param list<string> $rawHeaders */
    public static function fromRaw(array $rawHeaders, string $body): self
    {
        $statusCode = 0;
        $headers = [];

        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $statusCode = (int) $m[1];
                continue;
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return new self($statusCode, $headers, $body);
    }

    public function sessionCookie(): ?string
    {
        $setCookie = $this->headers['set-cookie'] ?? null;
        if ($setCookie === null) {
            return null;
        }

        $firstAttr = explode(';', $setCookie, 2)[0];

        return str_starts_with($firstAttr, 'PHPSESSID=') ? $firstAttr : null;
    }
}
