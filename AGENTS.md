> You are in **rushing/laravel-request-logs** — inbound and outbound HTTP request logging for Laravel, with correlation IDs, per-channel redaction pipelines, model-tracking, and prunable lightweight database references.

This is a standalone Laravel package (leaf, no family-repo dependencies). It provides
a middleware for logging inbound requests, a Guzzle handler for logging outbound
calls, a redaction pipeline for scrubbing sensitive data before it hits log channels,
and a `MassPrunable` `request_logs` table for lean long-retention storage.
