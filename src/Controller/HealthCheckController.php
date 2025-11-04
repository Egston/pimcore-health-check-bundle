<?php

namespace Egston\PimcoreHealthCheckBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Egston\PimcoreHealthCheckBundle\Service\HealthCheckRunner;

class HealthCheckController
{
    public function __construct(
        private readonly HealthCheckRunner $runner,
        private readonly string $path
    ) {
    }

    public function status(): JsonResponse
    {
        $result = $this->runner->run();

        $statusCode = match ($result['status']) {
            'ok' => 200,
            'failed' => 503,
            default => 503,
        };

        return new JsonResponse(
            [
                'status' => $result['status'],
                'path' => $this->path,
                'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'checks' => $result['checks'],
            ],
            $statusCode
        );
    }
}
