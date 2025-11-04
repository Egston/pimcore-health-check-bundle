<?php

namespace Egston\PimcoreHealthCheckBundle\Health;

class WritableTemporaryDirectoryCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly ?string $directory = null
    ) {
    }

    public function getName(): string
    {
        return 'temporary_storage';
    }

    public function assert(): void
    {
        $targetDirectory = $this->directory ?? sys_get_temp_dir();

        if (!is_dir($targetDirectory)) {
            throw new \RuntimeException(sprintf('Temporary directory "%s" is not a directory.', $targetDirectory));
        }

        if (!is_writable($targetDirectory)) {
            throw new \RuntimeException(sprintf('Temporary directory "%s" is not writable.', $targetDirectory));
        }

        $filename = $targetDirectory . DIRECTORY_SEPARATOR . 'healthcheck_' . bin2hex(random_bytes(6));

        if (@file_put_contents($filename, 'ok') === false) {
            throw new \RuntimeException(sprintf('Failed to write temporary file in "%s".', $targetDirectory));
        }

        @unlink($filename);
    }
}
