<?php

namespace Emmy\Ozioma\Tests;

use Emmy\Ozioma\OutgoingRequestLogger;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class OutgoingRequestLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    }

    private function allow(string ...$patterns): void
    {
        config(['ozioma.allowed' => $patterns]);
    }

    /** @param array<string, mixed> $options */
    private function sendThroughMiddleware(Request $request, array $options = [], ?callable $handler = null): mixed
    {
        $handler ??= fn () => new FulfilledPromise(new Response(200));

        return (OutgoingRequestLogger::make())($handler)($request, $options)->wait();
    }

    public function test_it_logs_a_request_to_an_allowed_url(): void
    {
        $this->allow('api.example.com/users');

        Http::get('https://api.example.com/users');

        $records = $this->logged();
        $this->assertCount(1, $records);
        $this->assertSame('Outgoing HTTP request', $records[0]->message);
        $this->assertSame(Level::Info, $records[0]->level);
        $this->assertSame('GET', $records[0]->context['method']);
        $this->assertSame('https://api.example.com/users', $records[0]->context['url']);
        $this->assertSame(200, $records[0]->context['status']);
        $this->assertArrayHasKey('duration_ms', $records[0]->context);
    }

    public function test_it_does_not_log_a_request_to_a_url_that_is_not_allowed(): void
    {
        $this->allow('api.example.com/users');

        Http::get('https://api.other.com/users');

        $this->assertCount(0, $this->logged());
    }

    public function test_it_logs_nothing_when_the_allowed_list_is_empty(): void
    {
        $this->allow();

        Http::get('https://api.example.com/users');

        $this->assertCount(0, $this->logged());
    }

    public function test_an_entry_without_a_wildcard_does_not_match_its_sub_paths(): void
    {
        $this->allow('api.example.com/users');

        Http::get('https://api.example.com/users/1');

        $this->assertCount(0, $this->logged());
    }

    public function test_a_trailing_wildcard_logs_every_path_under_the_url(): void
    {
        $this->allow('api.example.com/*');

        Http::get('https://api.example.com');
        Http::get('https://api.example.com/users');
        Http::post('https://api.example.com/users/1/cards');
        Http::get('https://api.other.com/users');

        $this->assertSame(
            ['https://api.example.com', 'https://api.example.com/users', 'https://api.example.com/users/1/cards'],
            array_map(fn ($record) => $record->context['url'], $this->logged()),
        );
    }

    public function test_a_lone_wildcard_logs_every_request(): void
    {
        $this->allow('*');

        Http::get('https://api.example.com/users');
        Http::get('http://localhost:8080/health');

        $this->assertCount(2, $this->logged());
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function urlMatchingCases(): array
    {
        return [
            'exact match' => ['api.example.com/users', 'https://api.example.com/users', true],
            'scheme on the entry is ignored' => ['https://api.example.com/users', 'http://api.example.com/users', true],
            'query string on the url is ignored' => ['api.example.com/users', 'https://api.example.com/users?page=2', true],
            'query string on the entry is ignored' => ['api.example.com/users?page=1', 'https://api.example.com/users', true],
            'trailing slash is ignored' => ['api.example.com/users/', 'https://api.example.com/users', true],
            'host is case-insensitive' => ['API.Example.com/users', 'https://api.example.COM/users', true],
            'path is case-sensitive' => ['api.example.com/Users', 'https://api.example.com/users', false],
            'port must match' => ['api.example.com/users', 'https://api.example.com:8443/users', false],
            'entry with port' => ['localhost:8080/*', 'http://localhost:8080/health', true],
            'wildcard on a path prefix' => ['api.example.com/transaction/*', 'https://api.example.com/transaction/verify/abc', true],
            'wildcard on a path prefix does not leak to siblings' => ['api.example.com/transaction/*', 'https://api.example.com/transfer', false],
            'wildcard matches the bare prefix' => ['api.example.com/transaction/*', 'https://api.example.com/transaction', true],
            'mid-string asterisk is not a wildcard' => ['api.*.com/users', 'https://api.example.com/users', false],
            'different host' => ['api.example.com/*', 'https://example.com/users', false],
        ];
    }

    #[DataProvider('urlMatchingCases')]
    public function test_it_matches_urls_against_allowed_entries(string $pattern, string $url, bool $expected): void
    {
        $this->assertSame($expected, OutgoingRequestLogger::isAllowed($url, [$pattern]));
    }

    public function test_the_log_option_set_to_false_skips_an_allowed_url(): void
    {
        $this->allow('*');

        Http::withOptions(['log' => false])->get('https://api.example.com/secret');

        $this->assertCount(0, $this->logged());
    }

    public function test_the_log_option_set_to_true_logs_a_url_that_is_not_allowed(): void
    {
        $this->allow();

        Http::withOptions(['log' => true])->get('https://api.example.com/users');

        $this->assertCount(1, $this->logged());
    }

    public function test_nothing_is_logged_when_disabled(): void
    {
        $this->allow('*');
        config(['ozioma.enabled' => false]);

        Http::withOptions(['log' => true])->get('https://api.example.com/users');

        $this->assertCount(0, $this->logged());
    }

    public function test_it_masks_sensitive_headers_case_insensitively(): void
    {
        $this->allow('*');

        Http::withToken('super-secret-token')
            ->withHeaders(['X-Api-Key' => 'key-123', 'X-Request-Id' => 'abc'])
            ->get('https://api.example.com/users');

        $headers = $this->logged()[0]->context['request_headers'];
        $this->assertSame(['[MASKED]'], $headers['Authorization']);
        $this->assertSame(['[MASKED]'], $headers['X-Api-Key']);
        $this->assertSame(['abc'], $headers['X-Request-Id']);
    }

    public function test_it_masks_sensitive_json_body_fields_at_any_depth(): void
    {
        $this->allow('*');

        Http::post('https://api.example.com/login', [
            'username' => 'john_doe',
            'password' => 'secret123',
            'card' => ['CVV' => '123', 'expiry' => '12/30'],
            'items' => [['pin' => '1234', 'name' => 'first']],
        ]);

        $body = $this->logged()[0]->context['request_body'];
        $this->assertSame('john_doe', $body['username']);
        $this->assertSame('[MASKED]', $body['password']);
        $this->assertSame('[MASKED]', $body['card']['CVV']);
        $this->assertSame('12/30', $body['card']['expiry']);
        $this->assertSame('[MASKED]', $body['items'][0]['pin']);
        $this->assertSame('first', $body['items'][0]['name']);
    }

    public function test_it_masks_sensitive_form_body_fields(): void
    {
        $this->allow('*');

        Http::asForm()->post('https://api.example.com/login', ['username' => 'john_doe', 'password' => 'secret123']);

        $body = $this->logged()[0]->context['request_body'];
        $this->assertSame('john_doe', $body['username']);
        $this->assertSame('[MASKED]', $body['password']);
    }

    public function test_it_logs_a_non_json_body_as_is(): void
    {
        $this->allow('*');

        $this->sendThroughMiddleware(new Request('POST', 'https://api.example.com/raw', ['Content-Type' => 'text/plain'], 'hello'));

        $this->assertSame('hello', $this->logged()[0]->context['request_body']);
    }

    public function test_masked_fields_mask_value_and_lists_are_configurable(): void
    {
        $this->allow('*');
        config([
            'ozioma.mask' => '***',
            'ozioma.sensitive_headers' => ['X-Tenant'],
            'ozioma.sensitive_body_fields' => ['email'],
        ]);

        Http::withHeaders(['X-Tenant' => 'acme'])->withToken('abc')->post('https://api.example.com/users', [
            'email' => 'john@example.com',
            'password' => 'secret123',
        ]);

        $context = $this->logged()[0]->context;
        $this->assertSame(['***'], $context['request_headers']['X-Tenant']);
        $this->assertSame(['Bearer abc'], $context['request_headers']['Authorization']);
        $this->assertSame('***', $context['request_body']['email']);
        $this->assertSame('secret123', $context['request_body']['password']);
    }

    public function test_4xx_and_5xx_responses_are_logged_as_warnings(): void
    {
        $this->allow('*');

        $this->sendThroughMiddleware(new Request('GET', 'https://api.example.com/missing'), handler: fn () => new FulfilledPromise(new Response(404)));
        $this->sendThroughMiddleware(new Request('GET', 'https://api.example.com/broken'), handler: fn () => new FulfilledPromise(new Response(500)));

        $records = $this->logged();
        $this->assertSame([Level::Warning, Level::Warning], [$records[0]->level, $records[1]->level]);
        $this->assertSame([404, 500], [$records[0]->context['status'], $records[1]->context['status']]);
    }

    public function test_failed_requests_are_logged_as_errors_and_rethrown(): void
    {
        $this->allow('*');

        try {
            $this->sendThroughMiddleware(
                new Request('GET', 'https://api.example.com/users'),
                handler: fn () => new RejectedPromise(new RuntimeException('Connection refused')),
            );
            $this->fail('The failure should have been rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Connection refused', $exception->getMessage());
        }

        $records = $this->logged();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Error, $records[0]->level);
        $this->assertSame('Outgoing HTTP request failed', $records[0]->message);
        $this->assertSame('Connection refused', $records[0]->context['error']);
    }

    public function test_failed_requests_to_urls_that_are_not_allowed_are_not_logged(): void
    {
        $this->allow('api.example.com/*');

        $this->expectException(RuntimeException::class);

        try {
            $this->sendThroughMiddleware(
                new Request('GET', 'https://api.other.com/users'),
                handler: fn () => new RejectedPromise(new RuntimeException('Connection refused')),
            );
        } finally {
            $this->assertCount(0, $this->logged());
        }
    }
}
