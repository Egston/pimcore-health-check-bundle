<?php

declare(strict_types=1);

namespace Egston\PimcoreHealthCheckBundle\Tests\Unit\Health;

use Egston\PimcoreHealthCheckBundle\Health\GraphQlSubRequestCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The request body is the contract between the probe and any default-deny
 * request-validation layer in front of the GraphQL endpoint: such layers key
 * on the body's operationName member and reject requests that omit it. These
 * tests pin the two body shapes — operationName present exactly when
 * configured, absent otherwise (so installations without a validation layer
 * see no behavioural change).
 */
final class GraphQlSubRequestCheckBodyTest extends TestCase
{
    public function testBodyIncludesOperationNameWhenConfigured(): void
    {
        $body = $this->captureDispatchedBody(operationName: 'HealthCheck');

        self::assertSame('HealthCheck', $body['operationName'] ?? null);
        self::assertSame('query HealthCheck { __typename }', $body['query'] ?? null);
    }

    public function testBodyOmitsOperationNameWhenNotConfigured(): void
    {
        $body = $this->captureDispatchedBody(operationName: null);

        self::assertArrayNotHasKey('operationName', $body);
        self::assertSame('query HealthCheck { __typename }', $body['query'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function captureDispatchedBody(?string $operationName): array
    {
        $captured = null;
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(
            function (Request $request) use (&$captured): Response {
                $captured = $request->getContent();

                return new Response('{"data":{"__typename":"Query"}}', 200);
            }
        );

        $check = new GraphQlSubRequestCheck(
            kernel: $kernel,
            logger: $this->createMock(LoggerInterface::class),
            name: 'test',
            path: '/safe',
            query: 'query HealthCheck { __typename }',
            timeout: 3.0,
            assertions: [],
            apiKey: null,
            operationName: $operationName,
        );

        $check->assert();

        self::assertIsString($captured, 'Kernel mock must have captured a request body.');

        return json_decode($captured, true, 512, JSON_THROW_ON_ERROR);
    }
}
