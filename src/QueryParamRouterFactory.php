<?php

declare(strict_types=1);

namespace Webware\Router;

use Psr\Container\ContainerInterface;

/**
 * Factory for creating QueryParamRouter instances.
 *
 * Reads configuration to determine if duplicate detection should be enabled.
 * Configuration key: QueryParamRouter::class => ['detect_duplicates' => true]
 */
final class QueryParamRouterFactory
{
    public function __invoke(ContainerInterface $container): QueryParamRouter
    {
        $config = $container->has('config') ? $container->get('config') : [];
        
        if (! is_array($config)) {
            $config = [];
        }
        
        $routerConfig = $config[QueryParamRouter::class] ?? [];
        
        if (! is_array($routerConfig)) {
            $routerConfig = [];
        }

        // Default to enabling duplicate detection
        $detectDuplicates = $routerConfig['detect_duplicates'] ?? true;

        $detector = null;
        if ($detectDuplicates) {
            $detector = $container->has(QueryParamDuplicateRouteDetector::class)
                ? $container->get(QueryParamDuplicateRouteDetector::class)
                : new QueryParamDuplicateRouteDetector();
        }

        return new QueryParamRouter($detector);
    }
}
