<?php

namespace Egston\PimcoreHealthCheckBundle\Health;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * GraphQL health check that dispatches the query as a Symfony sub-request
 * against the same kernel, instead of issuing an outbound HTTP call.
 *
 * Useful for per-pod readiness probes: covers routing, security gating,
 * controller, resolvers, and JSON serialization (the full production code
 * path) without depending on the cluster Service backing the pod — so it
 * remains satisfiable on cold start at replicas: 1, where an HTTP self-call
 * would deadlock on an empty endpoint set.
 *
 * Coverage trade-off vs `GraphQlEndpointCheck`: this check exercises the
 * Symfony kernel only — it does NOT exercise the upstream nginx / FastCGI
 * bridge. That layer is static configuration that fails loudly at boot;
 * runtime regressions there are better caught by external synthetic
 * monitoring against the public ingress.
 */
class GraphQlSubRequestCheck implements HealthCheckInterface
{
    use AssertsResponsePayload;

    /**
     * @param array<string, mixed> $assertions
     */
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly LoggerInterface $logger,
        private readonly string $name,
        private readonly string $path,
        private readonly string $query,
        private readonly array $assertions,
        private readonly ?string $apiKey = null,
    ) {
    }

    public function getName(): string
    {
        return 'graphql_internal_' . $this->name;
    }

    public function assert(): void
    {
        try {
            $request = Request::create(
                $this->buildUri(),
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['query' => $this->query], JSON_THROW_ON_ERROR)
            );

            $response = $this->kernel->handle(
                $request,
                HttpKernelInterface::SUB_REQUEST,
                false
            );
        } catch (\JsonException $exception) {
            $this->logger->error(
                'GraphQL sub-request health check could not encode request body.',
                $this->context(['exception_message' => $exception->getMessage()])
            );

            throw new \RuntimeException(
                sprintf('Could not encode GraphQL query as JSON: %s', $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'GraphQL sub-request health check kernel dispatch failed.',
                $this->context(['exception_message' => $exception->getMessage()])
            );

            throw new \RuntimeException(
                sprintf('GraphQL sub-request failed: %s', $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $this->logger->error(
                'GraphQL sub-request health check returned non-200 status.',
                $this->context([
                    'status_code' => $statusCode,
                    'body_excerpt' => mb_substr((string) $response->getContent(), 0, 512),
                ])
            );

            throw new \RuntimeException(sprintf('GraphQL sub-request returned status code %d.', $statusCode));
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->error(
                'GraphQL sub-request response contained invalid JSON.',
                $this->context(['exception_message' => $exception->getMessage()])
            );

            throw new \RuntimeException(
                sprintf('GraphQL response payload is not valid JSON: %s', $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        }

        if (isset($payload['errors']) && !empty($payload['errors'])) {
            $this->logger->error(
                'GraphQL sub-request response contains errors.',
                $this->context(['errors' => $payload['errors']])
            );

            throw new \RuntimeException('GraphQL response contains errors.');
        }

        $value = $this->resolvePath($payload, $this->assertions['path'] ?? null);

        $this->assertEquals($value, $this->assertions['equals'] ?? null);
        $this->assertContains($value, $this->assertions['contains'] ?? null);
        $this->assertEmptyState($value, $this->assertions['is_empty'] ?? null);
    }

    private function buildUri(): string
    {
        if ($this->apiKey === null) {
            return $this->path;
        }

        $separator = str_contains($this->path, '?') ? '&' : '?';

        return $this->path . $separator . 'apikey=' . rawurlencode($this->apiKey);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function context(array $extra = []): array
    {
        return array_merge([
            'check' => $this->getName(),
            'path' => $this->path,
            'api_key_provided' => $this->apiKey !== null,
        ], $extra);
    }
}
