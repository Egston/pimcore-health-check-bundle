<?php

namespace Egston\PimcoreHealthCheckBundle\Health;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class GraphQlEndpointCheck implements HealthCheckInterface
{
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

    /**
     * @param array<mixed> $payload
     */
    private function resolvePath(array $payload, ?string $path): mixed
    {
        if ($path === null || trim($path) === '') {
            return $payload;
        }

        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            $key = $this->normalizeSegment($segment);

            if (is_array($current)) {
                if (!array_key_exists($key, $current)) {
                    $this->logger->error(
                        'GraphQL health check path not found in response payload.',
                        $this->context([
                            'path' => $path,
                            'missing_segment' => $segment,
                            'current_keys' => array_keys($current),
                        ])
                    );

                    throw new \RuntimeException(sprintf('Response path "%s" not found in GraphQL payload.', $path));
                }

                $current = $current[$key];
                continue;
            }

            $this->logger->error(
                'GraphQL health check encountered non-traversable value while resolving path.',
                $this->context([
                    'path' => $path,
                    'segment' => $segment,
                    'current_type' => get_debug_type($current),
                ])
            );

            throw new \RuntimeException(sprintf('Unable to traverse path "%s"; current value is not traversable.', $path));
        }

        return $current;
    }

    private function normalizeSegment(string $segment): string|int
    {
        $trimmed = trim($segment);

        if (ctype_digit($trimmed)) {
            return (int) $trimmed;
        }

        return $trimmed;
    }

    private function assertEquals(mixed $value, mixed $expected): void
    {
        if ($expected === null) {
            return;
        }

        if ($value !== $expected) {
            throw new \RuntimeException(sprintf('Expected value "%s" but got "%s".', $this->stringify($expected), $this->stringify($value)));
        }
    }

    private function assertContains(mixed $value, mixed $expectedElement): void
    {
        if ($expectedElement === null) {
            return;
        }

        if (!is_iterable($value)) {
            throw new \RuntimeException('Value is not iterable; cannot check for containment.');
        }

        $items = is_array($value) ? $value : iterator_to_array($value);

        if (!in_array($expectedElement, $items, true)) {
            throw new \RuntimeException(sprintf('Expected element "%s" not found in iterable.', $this->stringify($expectedElement)));
        }
    }

    private function assertEmptyState(mixed $value, ?bool $expectedEmpty): void
    {
        if ($expectedEmpty === null) {
            return;
        }

        $isEmpty = empty($value);

        if ($expectedEmpty && !$isEmpty) {
            throw new \RuntimeException('Expected value to be empty.');
        }

        if (!$expectedEmpty && $isEmpty) {
            throw new \RuntimeException('Expected value to be non-empty.');
        }
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        $encoded = json_encode($value);

        if ($encoded === false) {
            return '[unserializable value]';
        }

        return $encoded;
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
