<?php

declare(strict_types=1);

namespace Webware\Router;

use Psr\Container\ContainerInterface;

/**
 * Factory for creating QueryParamDuplicateRouteDetector instances.
 */
final class QueryParamDuplicateRouteDetectorFactory
{
    public function __invoke(ContainerInterface $container): QueryParamDuplicateRouteDetector
    {
        return new QueryParamDuplicateRouteDetector();
    }
}
