<?php

declare(strict_types=1);

namespace Webware\Router;

use Mezzio\Router\Exception\RuntimeException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_key_exists;
use function count;
use function http_build_query;
use function preg_replace_callback;
use function sprintf;
use function str_replace;
use function urlencode;

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
        private ?QueryParamDuplicateRouteDetector $duplicateDetector = null
    ) {
    }

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
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->getQueryParams();

        // Find routes matching this path
        $candidateRoutes = $this->routesByPath[$path] ?? [];

        if (empty($candidateRoutes)) {
            return RouteResult::fromRouteFailure(null);
        }

        // Find best matching route based on query params and method
        $bestMatch = null;
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

            // Calculate match score (more specific query params = higher score)
            $score = count($this->getRouteQueryParamKeys($route));

            if ($score > $bestMatchScore) {
                $bestMatch = $route;
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
            throw new RuntimeException(sprintf(
                'Cannot generate URI for route "%s"; route not found',
                $name
            ));
        }

        $route = $this->routes[$name];
        $path = $route->getPath();

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
        $options = $route->getOptions();
        $requiredKeys = $options['query_params'] ?? [];

        if (! is_array($requiredKeys) || empty($requiredKeys)) {
            return true; // No query params required
        }

        // Check all required keys are present (case-sensitive)
        foreach ($requiredKeys as $key) {
            if (! is_string($key) || ! array_key_exists($key, $queryParams)) {
                return false;
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

        $options = $route->getOptions();
        $queryParams = $options['query_params'] ?? [];
        
        if (! is_array($queryParams)) {
            return [];
        }
        
        // Ensure it's a list of strings
        return array_values(array_filter($queryParams, 'is_string'));
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
