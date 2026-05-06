<?php

declare(strict_types=1);

namespace Egston\PimcoreHealthCheckBundle\Tests\Unit\Health;

use Egston\PimcoreHealthCheckBundle\Health\GraphQlSubRequestCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Body-excerpt redaction is the boundary between operator-controlled response
 * content (whatever resolver the configured `path` reaches) and an error-level
 * log entry that may forward to external aggregation. Tests assert observable
 * behaviour: when the kernel returns a non-200, the captured logger context
 * must contain the redacted form, never the raw secret.
 *
 * The redactor's intent is "false positives acceptable, false negatives are
 * not." Negative tests (the bug class that survived two rounds of review)
 * cover base64 / URL-credential / JSON-quoted shapes that earlier value
 * classes truncated mid-secret.
 */
final class GraphQlSubRequestCheckRedactionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function redactionCases(): array
    {
        return [
            'Bearer with base64 chars' => [
                'Authorization: Bearer abc/def+ghi==xyz',
                '[REDACTED]',
                ['abc/def', '+ghi==xyz'],
            ],
            'JWT' => [
                'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcdef',
                '[REDACTED]',
                ['eyJhbGci', 'eyJzdWIi'],
            ],
            'Basic auth with base64 credentials' => [
                '{"authorization":"Basic dXNlcjpwYXNz"}',
                '[REDACTED]',
                ['dXNlcjpwYXNz'],
            ],
            'JSON password field' => [
                '{"password":"hunter2","name":"foo"}',
                '[REDACTED]',
                ['hunter2'],
            ],
            'JSON api_key field with base64 chars' => [
                '{"api_key":"abc/def+ghi==xyz"}',
                '[REDACTED]',
                ['abc/def', '+ghi=='],
            ],
            'query-string apikey' => [
                'GET /endpoint?apikey=secret123',
                '[REDACTED]',
                ['secret123'],
            ],
            'DB URL with credentials' => [
                'mysql://user:pass@host/db',
                ':[REDACTED]@',
                ['pass'],
            ],
            'plain text passes through unchanged' => [
                'No secrets here, just words and {data: {ok: true}}',
                'No secrets here',
                [],
            ],
        ];
    }

    #[DataProvider('redactionCases')]
    public function testNon200BodyExcerptIsRedactedBeforeLogging(
        string $body,
        string $mustContain,
        array $mustNotContain,
    ): void {
        $loggerCalls = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context) use (&$loggerCalls): void {
                $loggerCalls[] = ['message' => $message, 'context' => $context];
            }
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->method('handle')->willReturn(new Response($body, 503));

        $check = new GraphQlSubRequestCheck(
            kernel: $kernel,
            logger: $logger,
            name: 'test',
            path: '/safe',
            query: '{ __typename }',
            timeout: 3.0,
            assertions: [],
        );

        try {
            $check->assert();
            self::fail('Expected RuntimeException for non-200 status.');
        } catch (\RuntimeException) {
            // Expected — assert() converts non-200 to RuntimeException.
        }

        $excerpt = null;
        foreach ($loggerCalls as $call) {
            if (isset($call['context']['body_excerpt'])) {
                $excerpt = $call['context']['body_excerpt'];
                break;
            }
        }
        self::assertNotNull($excerpt, 'Expected non-200 log to capture body_excerpt.');
        self::assertStringContainsString($mustContain, $excerpt);
        foreach ($mustNotContain as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $excerpt,
                sprintf('Redacted excerpt must not contain "%s"', $forbidden),
            );
        }
    }

    public function testNon200LogEmitsExpectedLiteralPrefix(): void
    {
        $loggerCalls = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context) use (&$loggerCalls): void {
                $loggerCalls[] = $message;
            }
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->method('handle')->willReturn(new Response('error', 500));

        $check = new GraphQlSubRequestCheck(
            kernel: $kernel,
            logger: $logger,
            name: 'test',
            path: '/safe',
            query: '{ __typename }',
            timeout: 3.0,
            assertions: [],
        );

        try {
            $check->assert();
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertContains(
            'GraphQL sub-request health check returned non-200 status.',
            $loggerCalls,
            'Literal log prefix must be stable for log-aggregation rules.'
        );
    }
}
