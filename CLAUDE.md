# CLAUDE.md

> **Note:** This repository (`egston/pimcore-health-check-bundle`) is a **home-grown Egston/Yageo project** — it has no upstream origin. It is an integral part of the Yageo Pimcore deployment. Feel free to modify it as needed.

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Pimcore 11.5+ bundle (`egston/pimcore-health-check-bundle`) that exposes a `/healthz` endpoint returning JSON status of configured health checks. It is not a standalone application — it is installed into `pimcore-installation/` via Composer (path repository from `./dev/pimcore-health-check-bundle`).

The bundle exposes the endpoint via Symfony routing; the host project decides how to wire it as a probe. Where this bundle is deployed today, the endpoint gates traffic to the pod (a 503 stops routing) — which is why cache operations on prod must always use the two-step `cache:clear --no-warmup` + `cache:warmup` sequence rather than `cache:clear` alone (which creates a gap where the probe could return 503). For the specific kubelet wiring, image, command, and probe-timing values in use, see `yageo-pimcore-k8s/dev/helmsman/dsf/gke.yaml` and the corresponding wiki page.

## Development

There are no tests, Makefile, or CI/CD pipelines in this repository. Development consists of editing PHP source files and validating by installing the bundle in a host Pimcore project.

To validate bundle registration in a host project:
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

- **SUB_REQUEST bypasses Symfony firewalls and `access_control`.** The extension rejects `path:` values whose URL-decoded, lowercased form begins with `/admin`, `/api/`, or `/asset/webdav` at container-compile time. Configure only routes intended for unauthenticated access (e.g. DataHub `public-content`).
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
