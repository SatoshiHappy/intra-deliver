<?php

namespace App\Support\OctaneFixture;

use Countable;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container as ConcreteContainer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Psr\Container\ContainerInterface;

final class InjectsContainerAndConfig
{
    public function __construct(
        public readonly ?Repository $config,
        public readonly Container|string $container,
        public readonly ContainerInterface&Countable $psrContainer,
        public readonly ConcreteContainer $concreteContainer,
        public readonly Application $application,
        public readonly ApplicationContract $applicationContract,
        public readonly ConfigRepository $concreteConfig,
    ) {}
}
