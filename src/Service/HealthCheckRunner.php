<?php

namespace Egston\PimcoreHealthCheckBundle\Service;

use Egston\PimcoreHealthCheckBundle\Health\HealthCheckInterface;

class HealthCheckRunner
{
    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(
        private readonly iterable $checks,
        private readonly bool $enabled
    ) {
    }

    public function run(): array
    {
        if (!$this->enabled) {
            return [
                'status' => 'disabled',
                'checks' => [],
            ];
        }

        $allHealthy = true;
        $results = [];

        foreach ($this->checks as $check) {
            $status = [
                'name' => $check->getName(),
                'status' => 'ok',
            ];

            try {
                $check->assert();
            } catch (\Throwable $exception) {
                $status['status'] = 'failed';
                $status['message'] = $exception->getMessage();
                $allHealthy = false;
            }

            $results[] = $status;
        }

        return [
            'status' => $allHealthy ? 'ok' : 'failed',
            'checks' => $results,
        ];
    }
}
