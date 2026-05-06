# CLAUDE.md

> **Note:** This is a public Pimcore 11+ bundle published as `egston/pimcore-health-check-bundle`, intended to be reusable across Pimcore installations. Keep any changes vendor-neutral — don't introduce references to specific consumer deployments, paths, sibling bundles, or hosting environments.

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Pimcore 11+ bundle that exposes a `/healthz` endpoint returning JSON status of configured health checks. It is not a standalone application — it is installed into a Pimcore project via Composer.

The bundle exposes the endpoint via Symfony routing; the host project decides how to wire it as a probe (Kubernetes liveness/readiness/startup, GCP/AWS load-balancer health check, etc.). When the endpoint gates traffic to a pod, cache operations should use the two-step `cache:clear --no-warmup` + `cache:warmup` sequence rather than `cache:clear` alone, since the latter creates a window where the probe could return 503.

## Development

A PHPUnit suite covers the security-relevant logic (path-safety denylist, service-ID collision detection, log redaction) and the response-payload assertion trait. Tests run on the host without booting Pimcore — the bundle's own `composer install` provisions a local `vendor/`:

```bash
composer install --ignore-platform-reqs    # one-time
vendor/bin/phpunit                         # runs the Unit suite
```

There are no Makefile or CI/CD pipelines in this repository. Beyond the unit suite, validate bundle registration in a host Pimcore project:

```bash
php bin/console debug:container egston
php bin/console debug:router | grep healthz
```

## Architecture

### Core Flow

1. A GET request hits the path configured in `egston_pimcore_health_check.path` (default: `/healthz`)
2. `HealthCheckController::status()` delegates to `HealthCheckRunner::run()`
3. `HealthCheckRunner` iterates all services tagged `egston.pimcore_health_check.check`
4. Each check calls `assert()` — an exception means failure
5. Returns HTTP 200 (`ok`) or 503 (`failed`); response is never cached

### Adding a Health Check

Implement `HealthCheckInterface` (`src/Health/HealthCheckInterface.php`) with two methods: `getName(): string` and `assert(): void`. Any service implementing this interface is auto-tagged and auto-wired into the runner via `autoconfigure`.

### GraphQL Checks

Two complementary GraphQL probe modes are available; both are off by default and registered programmatically per endpoint by `EgstonPimcoreHealthCheckExtension`.

`GraphQlEndpointCheck` (`graphql.endpoints[*]`) issues an outbound HTTP `POST` to a configured URL — covers the full nginx → FPM → kernel chain, but a self-referential URL pointing at the cluster's own Service deadlocks at cold start (the Service has zero Ready endpoints until the very probe being answered passes). Use for cross-pod / external GraphQL probes. Supports bearer token / API key auth, custom headers, and assertions.

`GraphQlSubRequestCheck` (`graphql_internal.endpoints[*]`) dispatches the same query as a Symfony sub-request through `HttpKernelInterface::SUB_REQUEST` — covers routing + controller + resolvers without an outbound HTTP hop, so it survives cold start. Two constraints to know:

- **SUB_REQUEST bypasses Symfony firewalls and `access_control`.** As an operator-misconfiguration guard, the extension rejects `path:` values that resolve to the `/admin` prefix at container-compile time — the one prefix every Pimcore deployment shares. Other firewall-protected prefixes (e.g. JWT-protected REST APIs, WebDAV mounts, custom host firewalls) vary by installation and are out of scope; the host's `security.yaml` is the source of truth, and operator review of `path:` against it carries the rest. Configure only routes intended for unauthenticated access (e.g. DataHub `public-content`).
- Does NOT exercise the upstream nginx / FastCGI bridge — pair with external synthetic monitoring for full-stack coverage.

Both checks share assertion logic via the `AssertsResponsePayload` trait — dot-notation path traversal with `equals` / `contains` / `is_empty` predicates, throwing `\RuntimeException` on mismatch.

### Configuration Schema

Defined in `src/DependencyInjection/Configuration.php`. Bundle config lives in the host project at `config/packages/egston_pimcore_health_check.yaml`:

```yaml
egston_pimcore_health_check:
  enabled: true
  path: /healthz
  graphql:
    enabled: false
    endpoints:
      - name: my-api
        url: 'https://api.example.com/graphql'
        query: '{ __typename }'
        timeout: 3.0
        authorization_bearer: '%env(API_TOKEN)%'
        assert:
          path: data.__typename
          equals: Query
```

### Key Files

| File | Purpose |
|------|---------|
| `src/EgstonPimcoreHealthCheckBundle.php` | Bundle entry point; registers routing |
| `src/DependencyInjection/Configuration.php` | YAML config schema (single source of truth for required/non-empty validation) |
| `src/DependencyInjection/EgstonPimcoreHealthCheckExtension.php` | Loads services; registers GraphQL endpoint service definitions dynamically; enforces SUB_REQUEST path safety + service-ID uniqueness |
| `src/Service/HealthCheckRunner.php` | Aggregates and runs all tagged checks |
| `src/Controller/HealthCheckController.php` | Single endpoint; returns 200/503 |
| `src/Health/HealthCheckInterface.php` | Contract for all checks |
| `src/Health/GraphQlEndpointCheck.php` | HTTP-based GraphQL probe (outbound `POST` via `HttpClientInterface`) |
| `src/Health/GraphQlSubRequestCheck.php` | In-process GraphQL probe via `HttpKernelInterface::SUB_REQUEST`; bypasses firewall, path-prefix-restricted |
| `src/Health/AssertsResponsePayload.php` | Shared trait — dot-path traversal + `equals`/`contains`/`is_empty` assertions for both GraphQL checks |
| `config/services.yaml` | Service wiring for the bundle itself |
