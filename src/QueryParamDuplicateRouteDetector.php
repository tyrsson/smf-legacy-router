<?php

declare(strict_types=1);

namespace Webware\Router;

use Mezzio\Router\Exception\DuplicateRouteException;
use Mezzio\Router\Route;

use function implode;
use function sort;
use function sprintf;

/**
 * Detects duplicate routes based on path, query parameter keys, and HTTP methods.
 *
 * A route is considered duplicate if it has:
 * - The same route name, OR
 * - The same path + query param keys + HTTP method combination
 *
 * Query param keys are compared case-sensitively but order-independently.
 */
final class QueryParamDuplicateRouteDetector
{
    /**
     * List of all routes indexed by name
     *
     * @var array<string, Route>
     */
    private array $routeNames = [];

    /**
     * Search structure for duplicate path-query-method detection
     * Indexed by: path -> query keys (sorted) -> method
     *
     * Example:
     * [
     *     '/api' => [
     *         'action,type' => [
     *             'any' => $route1,
     *         ],
     *         'action' => [
     *             'methods' => [
     *                 'GET' => $route2,
     *                 'POST' => $route3,
     *             ],
     *         ],
     *     ],
     * ]
     *
     * @var array<string, array<string, array{methods?: array<string, Route>, any?: Route}>>
     */
    private array $routePaths = [];

    private const ROUTE_SEARCH_ANY = 'any';
    private const ROUTE_SEARCH_METHODS = 'methods';

    /**
     * Determine if the route is duplicated in the current list.
     *
     * Checks if a route with the same name or path+query+method exists already;
     * if so, raises a DuplicateRouteException.
     *
     * @throws DuplicateRouteException On duplicate route detection.
     */
    public function detectDuplicate(Route $route): void
    {
        $this->throwOnDuplicate($route);
        $this->remember($route);
    }

    private function remember(Route $route): void
    {
        $this->routeNames[$route->getName()] = $route;

        $path = $route->getPath();
        $queryKey = $this->getQueryKey($route);

        if ($route->allowsAnyMethod()) {
            $this->routePaths[$path][$queryKey][self::ROUTE_SEARCH_ANY] = $route;
        } else {
            $allowedMethods = $route->getAllowedMethods() ?? [];
            foreach ($allowedMethods as $method) {
                $this->routePaths[$path][$queryKey][self::ROUTE_SEARCH_METHODS][$method] = $route;
            }
        }
    }

    private function throwOnDuplicate(Route $route): void
    {
        // Check for duplicate name
        if (isset($this->routeNames[$route->getName()])) {
            $this->duplicateRouteDetected($route);
        }

        $path = $route->getPath();
        $queryKey = $this->getQueryKey($route);

        // Check if this path+query combination exists
        if (! isset($this->routePaths[$path][$queryKey])) {
            return;
        }

        // Check for "any" method collision
        if (isset($this->routePaths[$path][$queryKey][self::ROUTE_SEARCH_ANY])) {
            $this->duplicateRouteDetected($route);
        }

        // If new route allows any method and there are specific methods registered
        if ($route->allowsAnyMethod() && isset($this->routePaths[$path][$queryKey][self::ROUTE_SEARCH_METHODS])) {
            $this->duplicateRouteDetected($route);
        }

        // Check for specific method collisions
        if (! $route->allowsAnyMethod()) {
            $allowedMethods = $route->getAllowedMethods() ?? [];
            foreach ($allowedMethods as $method) {
                if (isset($this->routePaths[$path][$queryKey][self::ROUTE_SEARCH_METHODS][$method])) {
                    $this->duplicateRouteDetected($route);
                }
            }
        }
    }

    private function duplicateRouteDetected(Route $duplicate): void
    {
        $allowedMethods = $duplicate->getAllowedMethods() ?: ['(any)'];
        $name = $duplicate->getName();

        $queryParams = $this->getQueryParamsFromRoute($duplicate);
        $queryInfo = empty($queryParams)
            ? ''
            : sprintf(' with query params [%s]', implode(',', $queryParams));

        throw new DuplicateRouteException(
            sprintf(
                'Duplicate route detected; path "%s"%s answering to methods [%s]%s',
                $duplicate->getPath(),
                $queryInfo,
                implode(',', $allowedMethods),
                $name ? sprintf(', with name "%s"', $name) : ''
            )
        );
    }

    /**
     * Get a normalized key for query parameters (sorted, case-sensitive).
     *
     * This ensures that routes with the same query params in different orders
     * are detected as duplicates.
     */
    private function getQueryKey(Route $route): string
    {
        $queryParams = $this->getQueryParamsFromRoute($route);

        if (empty($queryParams)) {
            return ''; // Empty key for routes without query params
        }

        // Sort for order-independence
        $sorted = $queryParams;
        sort($sorted, SORT_STRING);

        return implode(',', $sorted);
    }

    /**
     * Extract query parameter keys from route options.
     *
     * @return list<string>
     */
    private function getQueryParamsFromRoute(Route $route): array
    {
        $options = $route->getOptions();
        $queryParams = $options['query_params'] ?? [];
        
        if (! is_array($queryParams)) {
            return [];
        }
        
        // Ensure it's a list of strings
        return array_values(array_filter($queryParams, 'is_string'));
    }
}
