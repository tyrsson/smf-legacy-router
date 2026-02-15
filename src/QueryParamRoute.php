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

use Mezzio\Router\Route;
use Psr\Http\Server\MiddlewareInterface;

use function array_keys;
use function array_is_list;
use function implode;
use function is_int;

/**
 * Route that matches based on query parameters in addition to path and HTTP method.
 *
 * Extends the standard Route to support matching based on query parameter keys and values.
 * Routes match when all required query parameter keys are present in the request
 * (case-sensitive). Additional query parameters are allowed.
 *
 * Query parameters can be specified in two ways:
 * 1. List of keys only: ['action', 'u'] - matches when keys are present with any value
 * 2. Associative array with constraints: ['action' => 'profile', 'u' => null] - matches
 *    specific values, or any value when constraint is null
 *
 * @final
 *
 * @phpstan-ignore-next-line
 */
class QueryParamRoute extends Route
{
    /**
     * Query parameter constraints (param name => value constraint)
     * Value of null means any value is acceptable
     *
     * @var array<string, mixed>
     */
    private array $queryParamConstraints = [];

    /**
     * @param array<string, mixed>|list<string> $queryParamKeys Query parameter keys (backward compatible)
     *        or constraints (key => value pairs, null value = any value)
     */
    public function __construct(
        string $path,
        MiddlewareInterface $middleware,
        array $queryParamKeys = [],
        ?array $methods = self::HTTP_METHOD_ANY,
        ?string $name = null,
    ) {
        // Normalize query params to constraints format
        $this->queryParamConstraints = $this->normalizeQueryParams($queryParamKeys);

        // Generate name before calling parent constructor if name not provided
        if ($name === null || $name === '') {
            $name = $this->generateName($path, $this->queryParamConstraints, $methods);
        }

        parent::__construct($path, $middleware, $methods, $name);

        // Store query param constraints in options for router access
        $options                 = $this->getOptions();
        $options['query_params'] = $this->queryParamConstraints;
        $this->setOptions($options);
    }

    /**
     * Get the required query parameter keys for this route.
     *
     * @return list<string>
     */
    public function getQueryParamKeys(): array
    {
        return array_keys($this->queryParamConstraints);
    }

    /**
     * Get the query parameter constraints for this route.
     *
     * @return array<string, mixed> Map of param name => constraint value (null = any value)
     */
    public function getQueryParamConstraints(): array
    {
        return $this->queryParamConstraints;
    }

    /**
     * Check if the given query parameters match this route's requirements.
     *
     * All required query parameter keys must be present (case-sensitive).
     * If a constraint value is specified (not null), it must match exactly.
     * Additional query parameters are allowed.
     *
     * @param array<string, mixed> $queryParams Query parameters from the request
     */
    public function matchesQueryParams(array $queryParams): bool
    {
        // If no query params required, match any query params
        if (empty($this->queryParamConstraints)) {
            return true;
        }

        // Check all required keys are present and values match constraints
        foreach ($this->queryParamConstraints as $paramName => $constraint) {
            // Key must be present
            if (! array_key_exists($paramName, $queryParams)) {
                return false;
            }

            // If constraint is not null, value must match exactly
            if ($constraint !== null && $queryParams[$paramName] !== $constraint) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize query params input to constraints format.
     *
     * Supports backward compatibility:
     * - Indexed array ['action', 'u'] => ['action' => null, 'u' => null]
     * - Associative array ['action' => 'profile', 'u' => null] => unchanged
     *
     * @param array<string, mixed>|list<string> $queryParams
     * @return array<string, mixed>
     */
    private function normalizeQueryParams(array $queryParams): array
    {
        if (empty($queryParams)) {
            return [];
        }

        // Check if it's an indexed array (list of strings)
        if (array_is_list($queryParams)) {
            // Convert ['action', 'u'] to ['action' => null, 'u' => null]
            $normalized = [];
            foreach ($queryParams as $key) {
                if (is_string($key)) {
                    $normalized[$key] = null;
                }
            }
            return $normalized;
        }

        // Already associative, return as-is
        // @phpstan-ignore-next-line return.type - Input allows mixed keys
        return $queryParams;
    }

    /**
     * Generate route name based on path, query params, and methods.
     *
     * Format:
     * - /path (no query params, any method)
     * - /path?key1&key2 (query params with any values, any method)
     * - /path?action=profile&u (specific value for action, any for u)
     * - /path^GET:POST (no query params, specific methods)
     * - /path?key1&key2^GET:POST (query params and specific methods)
     *
     * @param array<string, mixed> $queryParamConstraints
     * @param list<string>|null $methods
     */
    private function generateName(string $path, array $queryParamConstraints, ?array $methods): string
    {
        $name = $path;

        // Add query params if present
        if (! empty($queryParamConstraints)) {
            $parts = [];
            foreach ($queryParamConstraints as $key => $value) {
                if ($value === null) {
                    // No value constraint, just show key
                    $parts[] = $key;
                } elseif (is_scalar($value)) {
                    // Has value constraint, show key=value
                    $parts[] = $key . '=' . (string) $value;
                }
            }
            $name .= '?' . implode('&', $parts);
        }

        // Add methods if specified
        if (is_array($methods)) {
            $name .= '^' . implode(self::HTTP_METHOD_SEPARATOR, $methods);
        }

        return $name;
    }
}
