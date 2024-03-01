<?php

declare(strict_types=1);

namespace DocBlocker\Factory;

class LaravelModelFactory implements ModelFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function __invoke(string $class): object
    {
        return \app($class); /** @phpstan-ignore-line */
    }
}
