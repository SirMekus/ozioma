<?php

declare(strict_types=1);

namespace Emmy\Ozioma;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

class OutgoingRequestLogger
{
    /**
     * Returns a Guzzle handler middleware that logs outgoing HTTP requests
     * whose URL is in the "allowed" list.
     *
     * Override the list per request with the "log" option:
     *   Http::withOptions(['log' => false])->get(...)  never log this request
     *   Http::withOptions(['log' => true])->get(...)   log it even if not allowed
     */
    public static function make(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                if (! static::shouldLog($request, $options)) {
                    return $handler($request, $options);
                }

                $startedAt = microtime(true);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $startedAt): ResponseInterface {
                        static::log($request, $response, $startedAt);

                        return $response;
                    },
                    function (Throwable $reason) use ($request, $startedAt): never {
                        static::log($request, null, $startedAt, $reason);

                        throw $reason;
                    }
                );
            };
        };
    }

    /**
     * Whether the URL matches an entry in the allowed list. An entry ending
     * in "*" matches every URL that starts with it; any other entry must
     * match exactly. Schemes and query strings are ignored on both sides.
     *
     * @param  array<int, string>  $allowed
     */
    public static function isAllowed(UriInterface|string $url, array $allowed): bool
    {
        $url = static::normalize((string) $url);

        foreach ($allowed as $pattern) {
            $pattern = static::normalize((string) $pattern);

            if (str_ends_with($pattern, '*')) {
                $prefix = substr($pattern, 0, -1);

                if (str_starts_with($url, $prefix) || $url === rtrim($prefix, '/')) {
                    return true;
                }

                continue;
            }

            if ($url === rtrim($pattern, '/')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $options */
    protected static function shouldLog(RequestInterface $request, array $options): bool
    {
        if (! config('ozioma.enabled', true)) {
            return false;
        }

        if (array_key_exists('log', $options)) {
            return (bool) $options['log'];
        }

        return static::isAllowed($request->getUri(), (array) config('ozioma.allowed', []));
    }

    /**
     * Strips the scheme, query string, fragment and trailing slash, and
     * lowercases the host, e.g. "HTTPS://Api.Example.com/v1/?a=b" → "api.example.com/v1".
     */
    protected static function normalize(string $url): string
    {
        $url = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($url));
        $url = preg_split('/[?#]/', $url, 2)[0];

        $slash = strpos($url, '/');
        $host = $slash === false ? $url : substr($url, 0, $slash);
        $path = $slash === false ? '' : substr($url, $slash);

        return rtrim(strtolower($host).$path, '/');
    }

    protected static function log(
        RequestInterface $request,
        ?ResponseInterface $response,
        float $startedAt,
        ?Throwable $error = null,
    ): void {
        $context = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'request_headers' => static::maskHeaders($request->getHeaders()),
            'request_body' => static::maskBody((string) $request->getBody(), $request->getHeaderLine('Content-Type')),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ];

        $channel = Log::channel(config('ozioma.channel', 'outgoing_requests'));

        if ($error !== null) {
            $context['error'] = $error->getMessage();
            $channel->error('Outgoing HTTP request failed', $context);

            return;
        }

        $context['status'] = $response?->getStatusCode();

        $level = ($response?->getStatusCode() ?? 0) >= 400 ? 'warning' : 'info';
        $channel->{$level}('Outgoing HTTP request', $context);
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, array<int, string>>
     */
    protected static function maskHeaders(array $headers): array
    {
        $sensitive = static::lowercased(config('ozioma.sensitive_headers', []));
        $mask = config('ozioma.mask', '[MASKED]');

        $masked = [];
        foreach ($headers as $name => $values) {
            $masked[$name] = in_array(strtolower((string) $name), $sensitive, true) ? [$mask] : $values;
        }

        return $masked;
    }

    /** @return string|array<array-key, mixed> */
    protected static function maskBody(string $body, string $contentType): string|array
    {
        if ($body === '') {
            return '';
        }

        if (str_contains($contentType, 'json')) {
            $decoded = json_decode($body, associative: true);
            if (is_array($decoded)) {
                return static::maskFields($decoded);
            }
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $parsed);

            return static::maskFields($parsed);
        }

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected static function maskFields(array $data): array
    {
        $sensitive = static::lowercased(config('ozioma.sensitive_body_fields', []));
        $mask = config('ozioma.mask', '[MASKED]');

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $data[$key] = $mask;
            } elseif (is_array($value)) {
                $data[$key] = static::maskFields($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    protected static function lowercased(array $values): array
    {
        return array_map(fn (string $value): string => strtolower($value), $values);
    }
}
