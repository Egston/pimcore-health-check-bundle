<?php

namespace Egston\PimcoreHealthCheckBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;

class EgstonPimcoreHealthCheckBundle extends AbstractPimcoreBundle
{
    public function getNiceName(): string
    {
        return 'Pimcore Health Check';
    }

    public function getDescription(): string
    {
        return 'Provides a lightweight /healthz endpoint for monitoring Pimcore installations.';
    }

    public function getRoutesConfig(): ?string
    {
        return __DIR__ . '/../config/routes.yaml';
    }
}
