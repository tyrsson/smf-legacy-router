<?php

declare(strict_types=1);

namespace Webware\Router;

use Mezzio\Router\Route;
use Psr\Http\Server\MiddlewareInterface;

use function array_keys;
use function count;
use function implode;

/**
 * Route that matches based on query parameters in addition to path and HTTP method.
 *
 * Extends the standard Route to support matching based on query parameter keys.
 * Routes match when all required query parameter keys are present in the request
 * (case-sensitive). Additional query parameters are allowed.
 *
 * @final
 * @phpstan-ignore-next-line
 */
class QueryParamRoute extends Route
{
    /**
     * @param list<string> $queryParamKeys Query parameter keys required for matching (case-sensitive)
     */
    public function __construct(
        string $path,
        MiddlewareInterface $middleware,
        private array $queryParamKeys = [],
        ?array $methods = self::HTTP_METHOD_ANY,
        ?string $name = null
    ) {
        // Generate name before calling parent constructor if name not provided
        if ($name === null || $name === '') {
            $name = $this->generateName($path, $queryParamKeys, $methods);
        }

        parent::__construct($path, $middleware, $methods, $name);

        // Store query param keys in options for router access
        $options = $this->getOptions();
        $options['query_params'] = $queryParamKeys;
        $this->setOptions($options);
    }

    /**
     * Get the required query parameter keys for this route.
     *
     * @return list<string>
     */
    public function getQueryParamKeys(): array
    {
        return $this->queryParamKeys;
    }

    /**
     * Check if the given query parameters match this route's requirements.
     *
     * All required query parameter keys must be present (case-sensitive).
     * Additional query parameters are allowed.
     *
     * @param array<string, mixed> $queryParams Query parameters from the request
     */
    public function matchesQueryParams(array $queryParams): bool
    {
        // If no query params required, match any query params
        if (empty($this->queryParamKeys)) {
            return true;
        }

        $requestKeys = array_keys($queryParams);

        // Check all required keys are present (case-sensitive)
        foreach ($this->queryParamKeys as $requiredKey) {
            if (! in_array($requiredKey, $requestKeys, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate route name based on path, query params, and methods.
     *
     * Format:
     * - /path (no query params, any method)
     * - /path?key1&key2 (query params, any method)
     * - /path^GET:POST (no query params, specific methods)
     * - /path?key1&key2^GET:POST (query params and specific methods)
     *
     * @param list<string> $queryParamKeys
     * @param list<string>|null $methods
     */
    private function generateName(string $path, array $queryParamKeys, ?array $methods): string
    {
        $name = $path;

        // Add query params if present
        if (! empty($queryParamKeys)) {
            $name .= '?' . implode('&', $queryParamKeys);
        }

        // Add methods if specified
        if (is_array($methods)) {
            $name .= '^' . implode(self::HTTP_METHOD_SEPARATOR, $methods);
        }

        return $name;
    }
}
