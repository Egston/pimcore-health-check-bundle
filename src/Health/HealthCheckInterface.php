<?php

namespace Egston\PimcoreHealthCheckBundle\Health;

interface HealthCheckInterface
{
    /**
     * Human readable name, used in responses.
     */
    public function getName(): string;

    /**
     * Return true when the system is healthy; throw an exception otherwise.
     *
     * @throws \Throwable when the check fails
     */
    public function assert(): void;
}
