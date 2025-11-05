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
- `graphql_*`: Available when configured; issues a GraphQL request and validates the response.

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
    graphql:
        enabled: true
        endpoints:
            - name: 'datahub_default'
              url: '/pimcore-graphql-webservices/datahub_default'
              query: |
                  query Health {
                      health {
                          status
                          version
                      }
                  }
              timeout: 1.5
              api_key: '%env(DATAHUB_API_KEY)%'
              assert:
                  path: 'data.health.status'
                  equals: 'OK'
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

## Troubleshooting

You can use following standard Pimcore commands to check for common extension issues:

```bash
bin/console pimcore:bundle:list
bin/console debug:config egston_pimcore_health_check
bin/console debug:router | grep health
```

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
