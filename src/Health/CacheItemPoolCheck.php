<?php

namespace Egston\PimcoreHealthCheckBundle\Health;

use Psr\Cache\CacheItemPoolInterface;

class CacheItemPoolCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cachePool
    ) {
    }

    public function getName(): string
    {
        return 'cache';
    }

    public function assert(): void
    {
        $cacheKey = 'healthcheck_' . bin2hex(random_bytes(6));

        try {
            $item = $this->cachePool->getItem($cacheKey);
            $item->set('ok');
            $item->expiresAfter(60);

            if (!$this->cachePool->save($item)) {
                throw new \RuntimeException('Failed to persist cache item.');
            }

            $storedItem = $this->cachePool->getItem($cacheKey);

            if (!$storedItem->isHit() || $storedItem->get() !== 'ok') {
                throw new \RuntimeException('Cache item could not be retrieved after write.');
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                sprintf('Cache check failed: %s', $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        } finally {
            $this->cachePool->deleteItem($cacheKey);
        }
    }
}
