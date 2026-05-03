# Pimcore Health Check Bundle

Pimcore 11 bundle that exposes a production friendly `GET /healthz` endpoint summarising the status of lightweight system checks.

## Features

- Bundles writable temporary storage, database connectivity, and cache read/write checks.
- Optional GraphQL endpoint probes driven by bundle configuration.
- Extensible through Symfony services implementing `HealthCheckInterface`.
- Configurable endpoint path via bundle configuration.

## Built-in Checks

- `temporary_storage`: Verifies the system can create and delete files in the temporary directory.
- `database`: Executes a simple `SELECT 1` on the default Doctrine connection.
- `cache`: Performs a write/read/delete cycle against the primary Symfony cache pool.
- `graphql_*`: Available when configured; issues an outbound HTTP GraphQL request and validates the response.
- `graphql_internal_*`: Available when configured; dispatches a GraphQL request as a Symfony sub-request against the same kernel — covers routing + controller + resolvers without needing an HTTP listener or cluster Service routing. Recommended for per-pod readiness probes.

## Installation

1. Enable the bundle in your Pimcore project:


Add to `config/bundles.php`:

```php
// ...
use Egston\PimcoreHealthCheckBundle\EgstonPimcoreHealthCheckBundle;

// ...

return [
    // ...
    EgstonPimcoreHealthCheckBundle::class => ['all' => true],
];
```

```bash
composer require egston/pimcore-health-check-bundle
bin/console pimcore:bundle:install EgstonPimcoreHealthCheckBundle
bin/console cache:clear
```

The bundle automatically wires its services and registers the route.

## Configuration

Create or update `config/packages/egston_pimcore_health_check.yaml` in your project:

```yaml
egston_pimcore_health_check:
    enabled: true
    path: '/healthz'
    graphql:
        enabled: false
```

- `enabled`: Toggle the bundle without uninstalling it.
- `path`: Override the HTTP path if `/healthz` is not suitable.
- `graphql`: Enable and configure GraphQL endpoint checks.

## GraphQL Checks

Enable GraphQL probes by providing one or more endpoints:

```yaml
egston_pimcore_health_check:
    enabled: true
    path: '/healthz'
    graphql:
        enabled: true
        endpoints:
            - name: 'public-content'
              # Check through nginx service inside the cluster since pimcore-php is running php-fpm without web server
              url: 'http://pimcore-nginx.pimcore.svc.cluster.local/pimcore-graphql-webservices/public-content'
              query: |
                  query Ping {
                    __typename
                  }
              timeout: 3
              api_key: '%env(DATAHUB_API_KEY)%'
              assert:
                  path: 'data.__typename'
                  equals: 'Query'
            - name: 'healthcheck'
              url: 'http://pimcore-nginx.pimcore.svc.cluster.local/pimcore-graphql-webservices/public-content'
              query: |
                  query HealthCheck {
                    getHealthCheckListing {
                      totalCount
                      edges {
                        node {
                          __typename
                          key
                          description
                          checkValue
                        }
                      }
                    }
                  }
              timeout: 3
              api_key: '%env(DATAHUB_API_KEY)%'
              assert:
                  path: 'data.getHealthCheckListing.edges[0].node.checkValue'
                  equals: 'I am ready!'

            - name: 'external_partner'
              url: 'https://partner.example/graphql'
              authorization_bearer: '%env(PARTNER_TOKEN)%'
              headers:
                  X-Tenant: 'egston'
              assert:
                  path: 'data.ping'
                  equals: 'pong'
```

- `name`: Displayed in the health report as `graphql_<name>`.
- `url`: Absolute GraphQL endpoint URL.
- `query`: GraphQL query payload (defaults to `{ __typename }`).
- `timeout`: Request timeout in seconds (defaults to `1.0`).
- `headers`: Optional HTTP headers such as authentication tokens.
- `authorization_bearer`: Convenience property to set an `Authorization: Bearer <token>` header when your endpoint expects it.
- `api_key`: Optional API key appended as `?apikey=<value>` when provided. Useful for Pimcore DataHub endpoints that expect query-string authentication.
- `assert.path`: Dot notation to the response field to validate (defaults to entire payload).
- `assert.equals`: Optional strict comparison value.
- `assert.contains`: Optional element that must exist within an array value.
- `assert.is_empty`: Expect the selected value to be empty (`true`) or non-empty (`false`).

Configure DataHub checks by pointing the `url` to `/pimcore-graphql-webservices/<client>` and supplying the associated API key via `api_key`. Other GraphQL providers remain fully configurable by combining `url`, `authorization_bearer`, and `headers`.

## In-Process (Sub-Request) GraphQL Checks

`graphql_internal` checks dispatch a synthetic POST request through the Symfony kernel via `HttpKernelInterface::SUB_REQUEST` — no outbound HTTP, no cluster Service involvement, no second FPM worker. Use these for per-pod readiness probes that need to verify the application's GraphQL endpoint actually serves data, without the cold-start deadlock that an HTTP self-call introduces (a Service has zero Ready endpoints until the very probe being answered passes — see https://github.com/Egston/pimcore-health-check-bundle for the full discussion).

```yaml
egston_pimcore_health_check:
    enabled: true
    path: '/readyz'
    graphql_internal:
        enabled: true
        endpoints:
            - name: 'public_content_smoke'
              # In-process URI path. Same path your nginx forwards to PHP.
              path: '/pimcore-graphql-webservices/public-content'
              api_key: '%env(DATAHUB_API_KEY)%'
              query: |
                  query HealthCheck {
                    getHealthCheckListing {
                      edges {
                        node {
                          __typename
                        }
                      }
                    }
                  }
              # Asserts the DataObject layer resolves end-to-end through
              # Pimcore-DH (≥ 1 HealthCheck DataObject must exist for the
              # assertion to pass — that is an operational responsibility
              # of the host installation, not the bundle).
              assert:
                  path: 'data.getHealthCheckListing.edges'
                  is_empty: false
```

Each entry surfaces in the response as `graphql_internal_<name>`. Configuration fields:

- `name`: Logical name (used in reporting as `graphql_internal_<name>`).
- `path`: In-process URI path to dispatch the synthetic request against. **Must target a route intended for unauthenticated access** — see the security caveat below.
- `query`: GraphQL query payload (defaults to `{ __typename }`).
- `api_key`: Optional API key appended as `?apikey=<value>` when provided. Same convention as the HTTP `graphql:` block, useful for Pimcore DataHub endpoints that expect query-string authentication.
- `assert.path` / `assert.equals` / `assert.contains` / `assert.is_empty`: Same assertion DSL as the HTTP `graphql:` block.

**Coverage trade-off**: `graphql_internal` does NOT exercise the upstream nginx / FastCGI bridge — only the Symfony kernel and below. nginx-config issues fail loudly at chart upgrade and are better caught by external synthetic monitoring (e.g. Google Cloud Monitoring uptime checks against the public ingress) than by a kubelet probe. Pair `graphql_internal` (per-pod readiness) with synthetic monitoring (cluster-level e2e) for full coverage.

**Security caveat — `SUB_REQUEST` skips Symfony firewalls**: `HttpKernelInterface::SUB_REQUEST` deliberately bypasses Symfony's `FirewallListener` and `access_control` rules — that's how the kernel implements forward-style internal dispatch. As a result, a `graphql_internal` probe against `/admin/...` or `/api/...` would reach the controller with whatever security token the parent request had (none, in a kubelet probe). Configure `path:` ONLY for endpoints intended to be world-accessible (e.g. the DataHub `public-content` endpoint, which authenticates via the `apikey` query param rather than firewall). Never aim `graphql_internal` at routes that depend on firewall enforcement to gate sensitive data or mutations.

**Recommended probe wiring**: with `graphql_internal` configured, the kubelet probe answering `/readyz` (`path:` above) belongs on the **PHP-FPM container**, dispatched via `cgi-fcgi` exec — that places restart authority on the pod that actually hosts the application logic. See the `yageo-pimcore-k8s` chart for an example DSF override.

## Troubleshooting

You can use following standard Pimcore commands to check for common extension issues:

```bash
# Verify that the bundle is installed and enabled
bin/console pimcore:bundle:list

# Inspect the current configuration loaded for the bundle
bin/console debug:config egston_pimcore_health_check

# Check if the healthz route is correctly registered
bin/console debug:router | grep health

# List all active health check services discovered by autoconfiguration
bin/console debug:container --tag=egston.pimcore_health_check.check
```

All errors around GraphQL checks are logged to Pimcore/Symfony log file for further inspection.

## Adding Custom Checks

1. Create a service that implements `Egston\PimcoreHealthCheckBundle\Health\HealthCheckInterface`.
2. Register it in your Symfony service configuration. Autoconfiguration will tag it automatically:

```php
<?php

namespace App\HealthCheck;

use Egston\PimcoreHealthCheckBundle\Health\HealthCheckInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExternalApiCheck implements HealthCheckInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient) {}

    public function getName(): string
    {
        return 'external_api';
    }

    public function assert(): void
    {
        $response = $this->httpClient->request('GET', 'https://status.example.com/ping', ['timeout' => 2]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Upstream status endpoint returned a non-200 response.');
        }
    }
}
```

Each check should throw an exception when the system is unhealthy. The response will include the exception message for observability tools.

## Response Format

Successful response (`200 OK`):

```json
{
  "status": "ok",
  "path": "/healthz",
  "timestamp": "2024-01-01T12:00:00+00:00",
  "checks": [
    {
      "name": "temporary_storage",
      "status": "ok"
    },
    {
      "name": "database",
      "status": "ok"
    },
    {
      "name": "cache",
      "status": "ok"
    }
  ]
}
```

Failed or disabled responses return `503 Service Unavailable` with the same JSON schema, including failure reasons.
