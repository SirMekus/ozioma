# Ozioma

*Ozioma* is Igbo for "good news" or "message". The package logs the outgoing HTTP requests your Laravel app makes with the `Http` client, but only to the URLs you allow, so you get a record of the third-party calls you care about (payment gateways, KYC providers, and so on) without logging every request. Sensitive headers and body fields are masked before anything is written.

Supports Laravel 11, 12 and 13 on PHP 8.2+.

## Installation

```bash
composer require sirmekus/ozioma
```

The service provider is auto-discovered. Publish the config if you want to change it:

```bash
php artisan vendor:publish --tag=ozioma-config
```

## Choosing what gets logged

Nothing is logged until you add URLs to `allowed` in `config/ozioma.php`:

```php
'allowed' => [
    'api.paystack.co/transaction/verify',  // only this path
    'api.paystack.co/transfer/*',          // every path under /transfer
    'api.flutterwave.com/*',               // every path on the host
],
```

- An entry without `*` matches that URL exactly. `api.paystack.co/transfer` does **not** match `api.paystack.co/transfer/123`.
- An entry ending in `*` matches every URL that starts with it, including the bare prefix (`api.flutterwave.com/*` also matches `api.flutterwave.com`).
- `*` on its own logs every request.
- The scheme and query string are ignored, the host is case-insensitive, and the port must match if the request uses one (`localhost:8080/*`).

### Per-request override

```php
Http::withOptions(['log' => false])->post(...); // never log this one
Http::withOptions(['log' => true])->get(...);   // log it even if not in the allowed list
```

`'enabled' => false` (or `OZIOMA_ENABLED=false`) turns logging off entirely, overrides included.

## Where it's logged

Entries go to the `outgoing_requests` channel (`OZIOMA_CHANNEL`). If your `config/logging.php` doesn't define that channel, the package adds a daily channel writing to `storage/logs/outgoing-requests.log`, configured under `default_channel`. Define the channel yourself to send entries anywhere else.

Each entry has `method`, `url`, `request_headers`, `request_body`, `duration_ms` and `status`:

| Outcome | Level | Message |
| --- | --- | --- |
| 1xx–3xx response | `info` | Outgoing HTTP request |
| 4xx–5xx response | `warning` | Outgoing HTTP request |
| Connection error / exception | `error` | Outgoing HTTP request failed (plus `error`) |

## Masking

Header names in `sensitive_headers` and body keys in `sensitive_body_fields` are replaced with `mask` (`[MASKED]` by default). Matching is case-insensitive, and body fields are masked at any depth in JSON and form-encoded bodies. Other bodies are logged as they are.

## Attaching it yourself

By default the logger is registered as a global middleware on the HTTP client. Set `register_globally` to `false` (`OZIOMA_GLOBAL=false`) to attach it only where you want; the `allowed` list still applies:

```php
use Emmy\Ozioma\OutgoingRequestLogger;

Http::withMiddleware(OutgoingRequestLogger::make())->get(...);
```

## Testing

```bash
composer test
```

## License

MIT
