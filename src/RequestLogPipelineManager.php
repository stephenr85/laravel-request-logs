<?php

namespace Rushing\RequestLogs;

use Illuminate\Support\Facades\Log;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Forgettable;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\RequestLogs\Contracts\RedactorInterface;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Processors\RequestLogProcessor;

/**
 * The map of Laravel log-channel name → the redactor pipeline that channel runs.
 *
 * Every configured channel receives every request log; the redactors under a channel decide what that
 * particular destination is allowed to see. Both methods enumerate channels: `log()` and `registerProcessors()`
 * engage the whole registry, never one entry.
 *
 * ## Declared, as of registry-kernel 38's sweep
 *
 * ⚠️ **The sweep ledger classified this row `b-config-fed`, and that is wrong.** Archetype **b** is for a
 * registry whose storage IS the config array — a `ConfigRegistry` reading `config(path)` through at read
 * time. This class never reads config at all: {@see RequestLogsServiceProvider} reads
 * `request-logs.channels` and hands the array in through {@see loadChannels()}, and
 * {@see addChannel()} then mutates the result independently of it. The store is a private array filled
 * by hand from two writers, which is archetype **a**, the accumulator — so the migration is the common
 * spine and no adapter.
 *
 * ## Keys are channel names, and they are now parsed
 *
 * A channel name becomes the relative key under `request-logs.channels`, so it must be {@see Key}-legal
 * — lowercase alphanumeric segments joined by `-`, `_` or `:`. Every channel name this package ships or
 * that either installing host configures already is (`request_logs`, `sentry_logs`,
 * `request_logs_raw`, `request_logs_redacted`, `ecfr_probe`), and a channel name is written by the
 * config's author with no knowledge of the host, so a malformed one is grammar rather than a fact about
 * a host: it throws, per the estate's rule about which checks may.
 *
 * ## What is deliberately NOT published
 *
 * No throwing per-channel accessor, and therefore no nullable twin owed. This class has never had a
 * by-key read — `getChannels()` returns the whole map and the two consumers iterate it — so there is no
 * accessor whose miss behaviour a migration could change. The kernel's own `resolve()`/`tryResolve()`
 * pair is delegated and is the by-key read anyone who wants one should use.
 */
#[IsRegistry(
    root: 'request-logs.channels',
    entryType: 'list<class-string<RedactorInterface>|array{0: class-string<RedactorInterface>, 1: array}>',
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    description: 'Request-log channels, each holding an ordered redactor pipeline. Registering the same channel replaces its pipeline. Logging visits every channel; RequestLogProcessor composes the redactors for each destination.',
)]
/**
 * @implements Registry<list<class-string<RedactorInterface>|array{0: class-string<RedactorInterface>, 1: array}>>
 */
class RequestLogPipelineManager implements Forgettable, Gated, Registry
{
    /** @var BasicRegistry<list<class-string<RedactorInterface>|array{0: class-string<RedactorInterface>, 1: array}>> */
    private BasicRegistry $channels;

    public function __construct()
    {
        $this->channels = BasicRegistry::for($this);
    }

    /**
     * Load channels from configuration, replacing whatever is registered.
     *
     * Wholesale replacement is what this method has always meant, and the kernel deliberately ships no
     * `clear()` ({@see Forgettable}, 08 D12), so it is spelled as a keyed teardown followed by the new
     * registrations. Registration order therefore follows the incoming array, exactly as the array
     * assignment it replaces did.
     *
     * @param  array<string, list<class-string<RedactorInterface>|array{0: class-string<RedactorInterface>, 1: array}>>  $channelConfig  ['channel_name' => [redactors...], ...]
     * @return $this
     */
    public function loadChannels(array $channelConfig, ?string $by = null): self
    {
        $this->clearChannels();

        foreach ($channelConfig as $channelName => $redactors) {
            $this->channels->register((string) $channelName, $redactors, $by);
        }

        return $this;
    }

    /**
     * Add a single channel with redactors.
     *
     * @param  list<class-string<RedactorInterface>|array{0: class-string<RedactorInterface>, 1: array}>  $redactors
     * @return $this
     */
    public function addChannel(string $channelName, array $redactors = [], ?string $by = null): self
    {
        $this->channels->register($channelName, $redactors, $by);

        return $this;
    }

    /**
     * The kernel's write. Source-compatible with {@see addChannel()}, which stays as the port's own
     * vocabulary: the entry widens to `mixed` and the return goes from `$this` to `static`.
     *
     * @param  list<class-string<RedactorInterface>|array{0: class-string<RedactorInterface>, 1: array}>  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->channels->register($key, $entry ?? [], $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->channels->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->channels->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->channels->tryResolve($key);
    }

    /** @return list<list<class-string<RedactorInterface>|array{0: class-string<RedactorInterface>, 1: array}>> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->channels->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->channels->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->channels->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->channels->authorizeWith($authorizer);

        return $this;
    }

    public function forget(RegistryKey|string $key): static
    {
        $this->channels->forget($key);

        return $this;
    }

    public function forgetBy(string $registrant): static
    {
        $this->channels->forgetBy($registrant);

        return $this;
    }

    /**
     * Register redaction processors on the configured log channels.
     */
    public function registerProcessors(): void
    {
        foreach ($this->getChannels() as $channelName => $redactors) {
            $processor = new RequestLogProcessor($redactors);
            Log::channel($channelName)?->getLogger()->pushProcessor($processor);
        }
    }

    /**
     * Log a RequestLogData through all configured channels.
     */
    public function log(RequestLogData $data): void
    {
        $context = $data->toArray();

        foreach ($this->getChannels() as $channelName => $redactors) {
            Log::channel($channelName)?->info($data->method.' '.$data->url, $context);
        }
    }

    /**
     * Get all configured channels, in registration order — the port's own vocabulary, rebuilt from
     * `relativeKeys()` so a caller keeps the channel-name spelling it registered with (spine step 7).
     *
     * @return array<string, list<class-string<RedactorInterface>|array{0: class-string<RedactorInterface>, 1: array}>>
     */
    public function getChannels(): array
    {
        $channels = [];

        foreach ($this->channels->relativeKeys() as $channelName) {
            $channels[$channelName] = $this->channels->tryResolve($channelName) ?? [];
        }

        return $channels;
    }

    /**
     * Clear all configured channels.
     *
     * @return $this
     */
    public function clearChannels(): self
    {
        foreach ($this->channels->relativeKeys() as $channelName) {
            $this->channels->forget($channelName);
        }

        return $this;
    }
}
