<?php

declare(strict_types=1);

namespace Egston\PimcoreHealthCheckBundle\Tests\Unit\Health;

use Egston\PimcoreHealthCheckBundle\Health\GraphQlEndpointCheck;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Bearer-token precedence is a security boundary: an operator-supplied
 * Authorization header (in `headers:`) must always win over the convenience
 * `authorization_bearer:` field, regardless of the casing operators use for
 * the header key. The redactor in `GraphQlSubRequestCheck` exists because
 * tokens flow through these probes; a regression flipping precedence here
 * would mean a Bearer token gets attached even when the operator chose a
 * different scheme — silent leak via the wire request.
 *
 * Tests pin the contract through the public `assert()` flow, capturing the
 * Guzzle request options to inspect the headers buildHeaders() produced.
 */
final class GraphQlEndpointCheckHeadersTest extends TestCase
{
    /**
     * @param callable(array<string, mixed>): array<string, mixed> $configure
     * @return array<string, string>
     */
    private function captureRequestHeaders(callable $configure): array
    {
        $capturedOptions = null;
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options = []) use (&$capturedOptions): Response {
                $capturedOptions = $options;
                return new Response(200, [], json_encode(['data' => null], JSON_THROW_ON_ERROR));
            }
        );

        $params = $configure([
            'httpClient' => $client,
            'logger' => $this->createMock(LoggerInterface::class),
            'name' => 'test',
            'url' => 'https://example.test/graphql',
            'query' => '{ __typename }',
            'timeout' => 3.0,
            'headers' => [],
            'assertions' => [],
            'apiKey' => null,
            'authorizationBearer' => null,
        ]);

        (new GraphQlEndpointCheck(...$params))->assert();

        self::assertNotNull($capturedOptions, 'Expected httpClient->request to be invoked.');
        self::assertArrayHasKey('headers', $capturedOptions);

        return $capturedOptions['headers'];
    }

    public function testContentTypeDefaultsToJsonWhenAbsent(): void
    {
        $headers = $this->captureRequestHeaders(fn (array $p): array => $p);

        self::assertSame('application/json', $headers['Content-Type'] ?? null);
    }

    public function testOperatorContentTypePreservedAndNotDuplicated(): void
    {
        $headers = $this->captureRequestHeaders(
            fn (array $p): array => [...$p, 'headers' => ['Content-Type' => 'application/graphql']]
        );

        self::assertSame('application/graphql', $headers['Content-Type'] ?? null);
        self::assertArrayNotHasKey('content-type', $headers, 'No duplicate lowercase key.');
    }

    public function testCaseInsensitiveContentTypeIsRespected(): void
    {
        $headers = $this->captureRequestHeaders(
            fn (array $p): array => [...$p, 'headers' => ['content-type' => 'application/graphql']]
        );

        self::assertSame('application/graphql', $headers['content-type'] ?? null);
        self::assertArrayNotHasKey('Content-Type', $headers, 'Should not also set canonical case.');
    }

    public function testAuthorizationBearerSetsHeaderWhenAbsent(): void
    {
        $headers = $this->captureRequestHeaders(
            fn (array $p): array => [...$p, 'authorizationBearer' => 'tok-abc']
        );

        self::assertSame('Bearer tok-abc', $headers['Authorization'] ?? null);
    }

    public function testOperatorAuthorizationOverridesBearer(): void
    {
        $headers = $this->captureRequestHeaders(
            fn (array $p): array => [
                ...$p,
                'headers' => ['Authorization' => 'Custom my-token'],
                'authorizationBearer' => 'tok-abc',
            ]
        );

        self::assertSame('Custom my-token', $headers['Authorization'] ?? null);
        self::assertStringNotContainsString(
            'tok-abc',
            json_encode($headers, JSON_THROW_ON_ERROR),
            'authorization_bearer must not leak when operator set Authorization.'
        );
    }

    public function testOperatorLowercaseAuthorizationOverridesBearer(): void
    {
        $headers = $this->captureRequestHeaders(
            fn (array $p): array => [
                ...$p,
                'headers' => ['authorization' => 'Custom my-token'],
                'authorizationBearer' => 'tok-abc',
            ]
        );

        self::assertSame('Custom my-token', $headers['authorization'] ?? null);
        self::assertArrayNotHasKey('Authorization', $headers, 'Should not double-set Authorization.');
        self::assertStringNotContainsString(
            'tok-abc',
            json_encode($headers, JSON_THROW_ON_ERROR),
        );
    }

    public function testNoAuthorizationHeaderWhenBothAreNull(): void
    {
        $headers = $this->captureRequestHeaders(fn (array $p): array => $p);

        self::assertArrayNotHasKey('Authorization', $headers);
        self::assertArrayNotHasKey('authorization', $headers);
    }
}
