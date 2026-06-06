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
        private readonly float $timeout,
        private readonly array $assertions,
        private readonly ?string $apiKey = null,
        private readonly ?string $operationName = null,
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
                json_encode($this->buildBody(), JSON_THROW_ON_ERROR)
            );

            $response = $this->dispatchWithTimeout($request);
        } catch (\JsonException $exception) {
            $this->logger->error(
                'GraphQL sub-request health check could not encode request body.',
                $this->context(['exception_message' => $exception->getMessage()])
            );

            // Public message intentionally omits $exception->getMessage() — the
            // runner echoes message text into the /readyz JSON response, which
            // is reachable from the public Internet. Underlying detail stays in
            // the application log via logger->error above.
            throw new \RuntimeException(
                'GraphQL sub-request could not encode query; see logs.',
                (int) $exception->getCode(),
                $exception
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'GraphQL sub-request health check kernel dispatch failed.',
                $this->context(['exception_message' => $exception->getMessage()])
            );

            // Doctrine / Pimcore exceptions can carry DB URIs, internal
            // hostnames, and stack-frame paths in getMessage(). Redact for the
            // public response; full detail in logs.
            throw new \RuntimeException(
                'GraphQL sub-request failed; see logs.',
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
                    'body_excerpt' => $this->redactPotentialSecrets(mb_substr((string) $response->getContent(), 0, 512)),
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
                'GraphQL sub-request response is not valid JSON; see logs.',
                (int) $exception->getCode(),
                $exception
            );
        }

        if (isset($payload['errors']) && !empty($payload['errors'])) {
            $this->logger->error(
                'GraphQL sub-request response contains errors.',
                $this->context(['errors' => $payload['errors']])
            );

            throw new \RuntimeException('GraphQL sub-request response contains errors; see logs.');
        }

        try {
            $value = $this->resolvePath($payload, $this->assertions['path'] ?? null);

            $this->assertEquals($value, $this->assertions['equals'] ?? null);
            $this->assertContains($value, $this->assertions['contains'] ?? null);
            $this->assertEmptyState($value, $this->assertions['is_empty'] ?? null);
        } catch (\RuntimeException $exception) {
            $this->logger->error(
                'GraphQL sub-request health check assertion failed.',
                $this->context([
                    'assertion_path' => $this->assertions['path'] ?? null,
                    'exception_message' => $exception->getMessage(),
                ])
            );

            throw $exception;
        }
    }

    /**
     * Include operationName only when configured: request-validation layers in
     * front of GraphQL endpoints (default-deny per-operation rules) key on the
     * body's operationName member and reject requests that omit it, so a probe
     * targeting such an endpoint must declare which operation it runs.
     *
     * @return array<string, string>
     */
    private function buildBody(): array
    {
        $body = ['query' => $this->query];
        if ($this->operationName !== null) {
            $body['operationName'] = $this->operationName;
        }

        return $body;
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
     * Bound the kernel sub-request via pcntl_alarm so a pathological resolver
     * doesn't keep an FPM worker pinned past the kubelet's timeoutSeconds:
     * kubelet kills the cgi-fcgi process but the FPM worker handling that
     * exec keeps running until the resolver returns. Without a PHP-side cap,
     * overlapping probe attempts can saturate the worker pool.
     *
     * Sub-second precision is rounded up to the nearest whole second since
     * pcntl_alarm() is integer-only. If pcntl is not loaded we fall back to
     * the kubelet's bound — better to run unbounded than to abort the probe.
     */
    private function dispatchWithTimeout(Request $request): mixed
    {
        if (!extension_loaded('pcntl')) {
            return $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST, false);
        }

        $timeout = $this->timeout;
        $previousAsync = pcntl_async_signals(true);

        try {
            // Install handler and arm the alarm INSIDE the try so a SIGALRM
            // delivered between scheduling and the kernel-handle call still
            // runs through the finally that restores async-signal state.
            pcntl_signal(SIGALRM, static function () use ($timeout): void {
                throw new \RuntimeException(sprintf('GraphQL sub-request exceeded timeout of %.1fs.', $timeout));
            });
            pcntl_alarm((int) max(1, ceil($timeout)));

            return $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST, false);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($previousAsync);
        }
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

    /**
     * The body excerpt is operator-controlled (whatever resolver the configured
     * `path` reaches) and lands in error-level context that may forward to
     * Stackdriver / Cloud Monitoring. Strip common secret shapes before they
     * leave the process. Conservative redaction — false positives are
     * acceptable here, false negatives are not.
     */
    private function redactPotentialSecrets(string $excerpt): string
    {
        // Value class includes base64 special chars (+ / =) and the colon used in
        // some token formats — real Bearer tokens and API keys regularly contain
        // these, and stopping at the first one would leak the rest of the secret.
        $patterns = [
            '/(Bearer|Basic|Negotiate|Digest)\s+[A-Za-z0-9._\-+\/=:]+/i' => '$1 [REDACTED]',
            '/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/' => '[REDACTED-JWT]',
            '/"?(api[_-]?key|password|secret|token|authorization)"?\s*[=:]\s*"?[A-Za-z0-9._\-+\/=:]+"?/i' => '$1=[REDACTED]',
            '/(\w+:\/\/[^:\/\s]+):([^@\/\s]+)@/' => '$1:[REDACTED]@',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $excerpt);
            if ($result === null) {
                // PCRE compile error, JIT exhaustion, or backtrack-limit hit on
                // adversarial input. Fail closed — the function exists to prevent
                // exactly this leak.
                $this->logger->warning(
                    'GraphQL sub-request log redactor regex failed; replacing excerpt with sentinel.',
                    $this->context(['pcre_error' => preg_last_error_msg()])
                );

                return '[REDACTED — regex failure]';
            }
            $excerpt = $result;
        }

        return $excerpt;
    }
}
