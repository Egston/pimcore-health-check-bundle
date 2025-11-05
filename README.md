# Pimcore Health Check Bundle

Pimcore 11 bundle that exposes a production friendly `GET /healthz` endpoint summarising the status of lightweight system checks.

## Features

- Bundles writable temporary storage, database connectivity, and cache read/write checks.
- Extensible through Symfony services implementing `HealthCheckInterface`.
- Configurable endpoint path via bundle configuration.

## Built-in Checks

- `temporary_storage`: Verifies the system can create and delete files in the temporary directory.
- `database`: Executes a simple `SELECT 1` on the default Doctrine connection.
- `cache`: Performs a write/read/delete cycle against the primary Symfony cache pool.

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
```

- `enabled`: Toggle the bundle without uninstalling it.
- `path`: Override the HTTP path if `/healthz` is not suitable.

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
