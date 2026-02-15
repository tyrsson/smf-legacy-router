<?php

declare(strict_types=1);

/**
 * This file is part of the Webware Smf Legacy Router package.
 *
 * Copyright (c) 2026 Joey (aka Tyrsson) Smith <jsmith@webinertia.net>
 * and contributors.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webware\Router;

use Mezzio\Router\Exception\RuntimeException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_key_exists;
use function array_is_list;
use function count;
use function http_build_query;
use function is_int;
use function sprintf;
use function str_replace;

/**
 * Router implementation that matches routes based on query parameters.
 *
 * Routes are matched against the request path and query parameter keys.
 * If a route specifies required query parameter keys, all must be present
 * in the request (case-sensitive). Query parameter values are included
 * in the matched params.
 */
final class QueryParamRouter implements RouterInterface
{
    /**
     * Routes indexed by name
     *
     * @var array<string, Route>
     */
    private array $routes = [];

    /**
     * Routes indexed by path for faster lookup
     *
     * @var array<string, list<Route>>
     */
    private array $routesByPath = [];

    public function __construct(
        private ?QueryParamDuplicateRouteDetector $duplicateDetector = null,
    ) {}

    public function addRoute(Route $route): void
    {
        if ($this->duplicateDetector !== null) {
            $this->duplicateDetector->detectDuplicate($route);
        }

        $this->routes[$route->getName()] = $route;

        // Index by path for faster matching
        $path = $route->getPath();
        if (! isset($this->routesByPath[$path])) {
            $this->routesByPath[$path] = [];
        }
        $this->routesByPath[$path][] = $route;
    }

    public function match(ServerRequestInterface $request): RouteResult
    {
        $path   = $request->getUri()->getPath();
        $method = $request->getMethod();

        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->getQueryParams();

        // Find routes matching this path
        $candidateRoutes = $this->routesByPath[$path] ?? [];

        if (empty($candidateRoutes)) {
            return RouteResult::fromRouteFailure(null);
        }

        // Find best matching route based on query params and method
        $bestMatch      = null;
        $bestMatchScore = -1;

        foreach ($candidateRoutes as $route) {
            // Check if query params match
            if (! $this->routeMatchesQueryParams($route, $queryParams)) {
                continue;
            }

            // Check if HTTP method matches
            if (! $route->allowsMethod($method)) {
                continue;
            }

            // Calculate match score
            // Higher score = more specific match
            // - Base score: number of query param keys
            // - Bonus: +100 for each parameter with a specific value constraint
            $constraints = $this->getRouteQueryParamConstraints($route);
            $score       = count($constraints);
            
            // Add bonus for value-specific constraints
            foreach ($constraints as $value) {
                if ($value !== null) {
                    $score += 100; // Large bonus for value-specific matching
                }
            }

            if ($score > $bestMatchScore) {
                $bestMatch      = $route;
                $bestMatchScore = $score;
            }
        }

        if ($bestMatch === null) {
            return RouteResult::fromRouteFailure(null);
        }

        // Extract matched query param values
        $matchedParams = $this->extractMatchedQueryParams($bestMatch, $queryParams);

        return RouteResult::fromRoute($bestMatch, $matchedParams);
    }

    /**
     * @param array<string, mixed> $substitutions
     * @param array<string, mixed> $options
     */
    public function generateUri(string $name, array $substitutions = [], array $options = []): string
    {
        if (! isset($this->routes[$name])) {
            throw new RuntimeException(sprintf('Cannot generate URI for route "%s"; route not found', $name));
        }

        $route = $this->routes[$name];
        $path  = $route->getPath();

        // Perform substitutions for path parameters (e.g., /users/{id})
        foreach ($substitutions as $key => $value) {
            if (is_scalar($value)) {
                $path = str_replace('{' . $key . '}', (string) $value, $path);
            }
        }

        // Add query parameters if provided
        if (isset($options['query']) && is_array($options['query']) && ! empty($options['query'])) {
            $path .= '?' . http_build_query($options['query']);
        }

        return $path;
    }

    /**
     * Check if route's query param requirements match the request's query params.
     *
     * @param array<string, mixed> $queryParams
     */
    private function routeMatchesQueryParams(Route $route, array $queryParams): bool
    {
        if ($route instanceof QueryParamRoute) {
            return $route->matchesQueryParams($queryParams);
        }

        // For standard routes, check if query_params option exists
        $options     = $route->getOptions();
        $constraints = $options['query_params'] ?? [];

        if (! is_array($constraints) || empty($constraints)) {
            return true; // No query params required
        }

        // Handle both old format (list) and new format (constraints)
        foreach ($constraints as $key => $value) {
            // If numeric key, it's old format (list of keys)
            if (is_int($key)) {
                if (! is_string($value) || ! array_key_exists($value, $queryParams)) {
                    return false;
                }
            } else {
                // New format (key => constraint)
                if (! array_key_exists($key, $queryParams)) {
                    return false;
                }
                // Check value constraint if not null
                if ($value !== null && $queryParams[$key] !== $value) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get query param keys from route.
     *
     * @return list<string>
     */
    private function getRouteQueryParamKeys(Route $route): array
    {
        if ($route instanceof QueryParamRoute) {
            return $route->getQueryParamKeys();
        }

        $options     = $route->getOptions();
        $queryParams = $options['query_params'] ?? [];

        if (! is_array($queryParams)) {
            return [];
        }

        // Handle both formats: list or associative array
        return array_keys($queryParams);
    }

    /**
     * Get query param constraints from route.
     *
     * @return array<string, mixed> Map of param name => constraint value
     */
    private function getRouteQueryParamConstraints(Route $route): array
    {
        if ($route instanceof QueryParamRoute) {
            return $route->getQueryParamConstraints();
        }

        $options     = $route->getOptions();
        $queryParams = $options['query_params'] ?? [];

        if (! is_array($queryParams)) {
            return [];
        }

        // If it's an indexed array (old format), convert to constraints
        if (array_is_list($queryParams)) {
            $constraints = [];
            foreach ($queryParams as $key) {
                if (is_string($key)) {
                    $constraints[$key] = null;
                }
            }
            return $constraints;
        }

        // Already in constraint format
        // @phpstan-ignore-next-line return.type - Route options contain mixed types
        return $queryParams;
    }

    /**
     * Extract matched query parameter values.
     *
     * Only includes values for the route's required query param keys.
     *
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function extractMatchedQueryParams(Route $route, array $queryParams): array
    {
        $requiredKeys = $this->getRouteQueryParamKeys($route);

        if (empty($requiredKeys)) {
            return [];
        }

        $matched = [];
        foreach ($requiredKeys as $key) {
            if (is_string($key) && array_key_exists($key, $queryParams)) {
                $matched[$key] = $queryParams[$key];
            }
        }

        return $matched;
    }
}
