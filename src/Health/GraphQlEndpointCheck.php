<?php

namespace Egston\PimcoreHealthCheckBundle\Health;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class GraphQlEndpointCheck implements HealthCheckInterface
{
    use AssertsResponsePayload;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $name,
        private readonly string $url,
        private readonly string $query,
        private readonly float $timeout,
        private readonly array $headers,
        private readonly array $assertions,
        private readonly ?string $apiKey = null,
        private readonly ?string $authorizationBearer = null
    ) {
    }

    public function getName(): string
    {
        return 'graphql_' . $this->name;
    }

    public function assert(): void
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->buildUrl(),
                [
                    'headers' => $this->buildHeaders(),
                    'json' => [
                        'query' => $this->query,
                    ],
                    'timeout' => $this->timeout,
                ]
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                throw new \RuntimeException(sprintf('GraphQL endpoint returned status code %d.', $statusCode));
            }

            $body = (string) $response->getBody();

            /** @var array<string, mixed> $payload */
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $exception) {
            $this->logger->error(
                'GraphQL health check HTTP request failed.',
                $this->context([
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                ])
            );

            throw new \RuntimeException(
                sprintf('GraphQL request failed: %s', $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        } catch (\JsonException $exception) {
            $this->logger->error(
                'GraphQL health check response contained invalid JSON.',
                $this->context([
                    'exception_message' => $exception->getMessage(),
                ])
            );

            throw new \RuntimeException(
                sprintf('GraphQL response payload is not valid JSON: %s', $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'GraphQL health check unexpected failure.',
                $this->context([
                    'exception_message' => $exception->getMessage(),
                ])
            );

            throw new \RuntimeException(
                sprintf('GraphQL request failed: %s', $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        }

        if (isset($payload['errors']) && !empty($payload['errors'])) {
            $this->logger->error(
                'GraphQL health check response contains errors.',
                $this->context([
                    'errors' => $payload['errors'],
                ])
            );

            throw new \RuntimeException('GraphQL response contains errors.');
        }

        $value = $this->resolvePath($payload, $this->assertions['path'] ?? null);

        $this->assertEquals($value, $this->assertions['equals'] ?? null);
        $this->assertContains($value, $this->assertions['contains'] ?? null);
        $this->assertEmptyState($value, $this->assertions['is_empty'] ?? null);
    }

    private function buildUrl(): string
    {
        if ($this->apiKey === null) {
            return $this->url;
        }

        return $this->appendQueryParameter($this->url, 'apikey', $this->apiKey);
    }

    private function buildHeaders(): array
    {
        $headers = $this->headers;

        // Ensure JSON content type is set unless explicitly overridden.
        $lowercaseKeys = array_change_key_case($headers, CASE_LOWER);

        if ($this->authorizationBearer !== null && !isset($lowercaseKeys['authorization'])) {
            $headers['Authorization'] = 'Bearer ' . $this->authorizationBearer;
            $lowercaseKeys['authorization'] = 'authorization';
        }

        if (!isset($lowercaseKeys['content-type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function appendQueryParameter(string $url, string $key, string $value): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function context(array $extra = []): array
    {
        return array_merge([
            'check' => $this->getName(),
            'endpoint' => $this->url,
            'api_key_provided' => $this->apiKey !== null,
        ], $extra);
    }
}
