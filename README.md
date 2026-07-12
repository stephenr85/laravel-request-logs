# Laravel Request Logs

Inbound **and** outbound HTTP request logging for Laravel. Capture request/response
audit trails with correlation IDs, scrub sensitive data through per-channel redaction
pipelines, associate the models a request touched, and keep a lean, prunable database
reference for long-retention auditing.

- **Inbound** — a middleware logs requests passing through any middleware group.
- **Outbound** — a Guzzle handler logs the calls your app makes to third parties.
- **Redaction** — each log channel runs its own pipeline of redactors (dot-notation
  paths with `*` / `**` wildcards, closure masking), applied as a Monolog processor so
  you can't leak by logging elsewhere.
- **Model-tracking** — add a trait and the models created/updated during a correlated
  request are auto-associated with its log row.
- **Lean storage** — the heavy payload flows to your log channels; only a lightweight
  reference row (`request_logs`) hits the database, and it's `MassPrunable`.

> Ported from an earlier `prahsys/laravel-api-logs`. This is a clean, renamed first
> release: everything is `RequestLog*` under `Rushing\RequestLogs\`, and the residual
> "idempotency" naming is gone (the correlation-header feature remains).

## Installation

```bash
composer require rushing/laravel-request-logs
```

The service provider is auto-discovered. Publish the config and migrations:

```bash
php artisan vendor:publish --tag=laravel-request-logs-config
php artisan vendor:publish --tag=laravel-request-logs-migrations
php artisan migrate
```

Requires PHP 8.2+, Laravel 11/12/13, and `spatie/laravel-data ^4`. To log outbound
calls you also need `guzzlehttp/guzzle ^7`.

## Inbound logging

Add the middleware to a group (e.g. `api`) in your HTTP kernel, after
`SubstituteBindings`:

```php
'api' => [
    // ...
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \Rushing\RequestLogs\Http\Middleware\RequestLogMiddleware::class,
],
```

Each request through the group writes a `request_logs` row and fires
`CompleteRequestLogEvent`. `OPTIONS` (CORS preflight) requests and any
`request-logs.exclude_paths` (wildcards supported) are skipped.

## Correlation header

Requests are correlated by a header, default **`X-Request-Id`**:

```php
'correlation' => [
    'header_name' => 'X-Request-Id',
    'ensure_header' => true, // auto-generate if missing; set false to only log correlated requests
],
```

> **Porting from `prahsys/laravel-api-logs`?** The default correlation header changed
> from `Idempotency-Key` to `X-Request-Id`. If you relied on the old default, set
> `request-logs.correlation.header_name` back to `Idempotency-Key`.

## Redaction pipelines

Each log channel gets its own list of redactors. Raw and redacted destinations can
carry different policies:

```php
'channels' => [
    'request_logs_raw' => [],

    'request_logs_redacted' => [
        \Rushing\RequestLogs\Redactors\CommonHeaderFieldsRedactor::class,
        \Rushing\RequestLogs\Redactors\CommonBodyFieldsRedactor::class,
    ],
],
```

Each key is a channel defined in `config/logging.php`. Redactors run as a Monolog
processor over the log record context. A redactor config may be a class name, a
`[class, ...args]` tuple, or an instance.

### DotNotationRedactor

The base redactor scrubs values by dot-notation path, with wildcards:

```php
use Rushing\RequestLogs\Redactors\DotNotationRedactor;

// exact path
new DotNotationRedactor(['request.body.password']);

// single-level wildcard (one level)
new DotNotationRedactor(['request.body.*.token']);

// deep wildcard (any depth) — redact `card.number` wherever it appears
new DotNotationRedactor(['**.card.number']);

// closure replacement — mask instead of fully redacting (e.g. last 4)
new DotNotationRedactor(
    ['response.body.card'],
    fn ($value, $path, $context) => '**** '.substr((string) $value, -4),
);
```

`CommonHeaderFieldsRedactor` and `CommonBodyFieldsRedactor` extend it with sensible
defaults (auth headers, cookies, passwords, tokens, api keys, …) and accept
`$additionalPaths` in their constructor. Write your own domain redactors (PCI, HIPAA,
PII) by extending `DotNotationRedactor`, or implement
`Rushing\RequestLogs\Contracts\RedactorInterface` (`handle($data, $next)`) directly.

## Model tracking

Add `HasRequestLogs` to any model. Instances saved during a correlated request are
auto-associated with that request's log row:

```php
use Illuminate\Database\Eloquent\Model;
use Rushing\RequestLogs\Models\HasRequestLogs;

class Payment extends Model
{
    use HasRequestLogs;
}
```

Then query both directions:

```php
// requests that touched a model
$payment->requestLogs;
$payment->latestRequestLog();

// models a request touched
$log = RequestLog::where('request_id', $id)->first();
$log->getRelatedModels(Payment::class)->get();
```

The association work happens in a **queued** listener
(`CompleteRequestLogListener`), so request latency isn't paid for the bookkeeping.
Run a queue worker to process it.

> Model tracking uses UUID morph keys (`request_log_models.model_id` is a UUID). Track
> models with `HasUuids` primary keys; other key types are simply not associated.

## Outbound logging (Guzzle)

Push the Guzzle handler onto your client's stack:

```php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Client;
use Rushing\RequestLogs\Http\Middleware\GuzzleRequestLogMiddleware;

$stack = HandlerStack::create();
$stack->push(app(GuzzleRequestLogMiddleware::class));

$client = new Client(['handler' => $stack]);
```

Outbound entries are tagged (`meta.type = outbound`, `meta.client = guzzle`), reuse the
same `CompleteRequestLogEvent`, and propagate the correlation ID onto the outbound
request. Control them with:

```php
'outbound' => [
    'enabled' => true,
    'exclude_hosts' => ['*.internal.example.com'], // wildcards
],
```

Skip an individual call with the `request_logs_skip` request option:

```php
$client->get('https://monitoring.example.com/ping', ['request_logs_skip' => true]);
```

## Retention / pruning

`RequestLog` is `MassPrunable`. Configure the window and schedule `model:prune`:

```php
'database' => [
    'pruning' => ['ttl_hours' => 24 * 365], // default 365 days
],
```

```php
// routes/console.php or a scheduler
$schedule->command('model:prune', ['--model' => [\Rushing\RequestLogs\Models\RequestLog::class]])->daily();
```

## Connection-agnostic storage

The package never hardcodes a connection. Set where the table lives:

```php
'database' => [
    'connection' => null, // null = the app's default connection
],
```

Publish the migrations to `database/migrations/` (central) or a per-tenant path as your
tenancy demands; the model and the association writes both resolve
`request-logs.database.connection`.

## Extension points

- Override the model via `request-logs.models.request_log` (extend `RequestLog`).
- Override the data class via `request-logs.data.request_log_data` (extend
  `RequestLogData`) to add payload columns, etc.
- Listen to `CompleteRequestLogEvent` to hang custom processing off request completion.

## Testing

```bash
composer test   # Pest
composer pint   # style
```
