<?php

namespace Rushing\RequestLogs\Redactors;

use Rushing\RequestLogs\Contracts\RedactorInterface;

class DotNotationRedactor implements RedactorInterface
{
    protected array $sensitivePaths;

    protected string|\Closure $replacement;

    public function __construct(array $sensitivePaths, string|\Closure $replacement = '[REDACTED]')
    {
        $this->sensitivePaths = $sensitivePaths;
        $this->replacement = $replacement;
    }

    public function handle(mixed $data, \Closure $next): mixed
    {
        $redactedData = $this->redactData($data);

        return $next($redactedData);
    }

    protected function redactData(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $result = $data;

        foreach ($this->sensitivePaths as $path) {
            if ($this->hasWildcard($path)) {
                $result = $this->redactWildcardPath($result, $path);
            } else {
                $value = data_get($result, $path);
                if ($value !== null) {
                    $redactedValue = $this->getReplacementValue($value, $path, $result);
                    data_set($result, $path, $redactedValue);
                }
            }
        }

        return $result;
    }

    protected function hasWildcard(string $path): bool
    {
        return str_contains($path, '*');
    }

    protected function redactWildcardPath(array $data, string $path): array
    {
        $result = $data;

        // Handle deep wildcard (**) patterns.
        if (str_contains($path, '**')) {
            $this->redactDeepWildcardPath($result, $path);
        } else {
            // Handle regular wildcard (*) patterns.
            $pathParts = explode('.', $path);
            $this->redactWildcardRecursive($result, $pathParts, []);
        }

        return $result;
    }

    protected function redactWildcardRecursive(array &$data, array $pathParts, array $currentPath): void
    {
        if (empty($pathParts)) {
            return;
        }

        $part = array_shift($pathParts);

        if ($part === '*') {
            // Single level wildcard.
            $currentData = data_get($data, implode('.', $currentPath));
            if (! is_array($currentData)) {
                return;
            }

            foreach ($currentData as $key => $value) {
                $newPath = array_merge($currentPath, [$key]);

                if (empty($pathParts)) {
                    // End of the path — redact this value.
                    $fullPath = implode('.', $newPath);
                    $originalValue = data_get($data, $fullPath);
                    $redactedValue = $this->getReplacementValue($originalValue, $fullPath, $data);
                    data_set($data, $fullPath, $redactedValue);
                } else {
                    // Continue recursing with the remaining path parts.
                    $this->redactWildcardRecursive($data, $pathParts, $newPath);
                }
            }
        } else {
            $newPath = array_merge($currentPath, [$part]);

            if (empty($pathParts)) {
                // End of the path — redact this value.
                $fullPath = implode('.', $newPath);
                $originalValue = data_get($data, $fullPath);
                if ($originalValue !== null) {
                    $redactedValue = $this->getReplacementValue($originalValue, $fullPath, $data);
                    data_set($data, $fullPath, $redactedValue);
                }
            } else {
                // Continue recursing with the remaining path parts.
                $this->redactWildcardRecursive($data, $pathParts, $newPath);
            }
        }
    }

    /**
     * Handle deep wildcard (**) patterns.
     */
    protected function redactDeepWildcardPath(array &$data, string $path): void
    {
        // Split the path by ** to get the prefix and suffix.
        $parts = explode('**', $path, 2);
        $prefix = trim($parts[0], '.');
        $suffix = isset($parts[1]) ? trim($parts[1], '.') : '';

        // Find all paths that match the pattern.
        $matchingPaths = $this->findDeepWildcardPaths($data, $prefix, $suffix);

        // Redact each matching path.
        foreach ($matchingPaths as $matchingPath) {
            $value = data_get($data, $matchingPath);
            if ($value !== null) {
                $redactedValue = $this->getReplacementValue($value, $matchingPath, $data);
                data_set($data, $matchingPath, $redactedValue);
            }
        }
    }

    /**
     * Find all paths that match a deep wildcard pattern.
     */
    protected function findDeepWildcardPaths(array $data, string $prefix, string $suffix, string $currentPath = ''): array
    {
        $paths = [];

        foreach ($data as $key => $value) {
            $fullPath = $currentPath ? "$currentPath.$key" : $key;

            // Check if this path matches our pattern.
            if ($this->pathMatches($fullPath, $prefix, $suffix)) {
                $paths[] = $fullPath;
            }

            // Recurse into arrays.
            if (is_array($value)) {
                $paths = array_merge($paths, $this->findDeepWildcardPaths($value, $prefix, $suffix, $fullPath));
            }
        }

        return $paths;
    }

    /**
     * Check if a path matches the prefix and suffix pattern.
     */
    protected function pathMatches(string $path, string $prefix, string $suffix): bool
    {
        // If no prefix, the path should end with the suffix.
        if (empty($prefix)) {
            return empty($suffix) || str_ends_with($path, $suffix);
        }

        // If no suffix, the path should start with the prefix.
        if (empty($suffix)) {
            return str_starts_with($path, $prefix);
        }

        // The path should start with the prefix and end with the suffix.
        return str_starts_with($path, $prefix) && str_ends_with($path, $suffix);
    }

    /**
     * Get the replacement value for a sensitive field.
     *
     * @param  mixed  $value  The original value being redacted
     * @param  string  $path  The dot notation path to the value
     * @param  array  $context  The full data context
     */
    protected function getReplacementValue(mixed $value, string $path, array $context): mixed
    {
        if ($this->replacement instanceof \Closure) {
            return call_user_func($this->replacement, $value, $path, $context);
        }

        return $this->replacement;
    }
}
