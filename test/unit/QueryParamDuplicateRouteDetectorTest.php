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

namespace WebwareTest\Router;

use Mezzio\Router\Exception\DuplicateRouteException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;
use Webware\Router\QueryParamDuplicateRouteDetector;
use Webware\Router\QueryParamRoute;

#[CoversClass(QueryParamDuplicateRouteDetector::class)]
#[UsesClass(QueryParamRoute::class)]
class QueryParamDuplicateRouteDetectorTest extends TestCase
{
    private QueryParamDuplicateRouteDetector $detector;

    private MiddlewareInterface $middleware;

    protected function setUp(): void
    {
        $this->detector   = new QueryParamDuplicateRouteDetector();
        $this->middleware = $this->createMock(MiddlewareInterface::class);
    }

    #[DoesNotPerformAssertions()]
    public function testAllowsSamePathWithDifferentQueryParams(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['type']);

        $this->detector->detectDuplicate($route1);
        $this->detector->detectDuplicate($route2); // Should not throw
    }

    public function testThrowsOnDuplicatePathAndQueryParams(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action']);

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage('Duplicate route detected');
        $this->detector->detectDuplicate($route2);
    }

    public function testThrowsOnDuplicatePathQueryParamsAndMethod(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action'], ['GET']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action'], ['GET']);

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->detector->detectDuplicate($route2);
    }

    #[DoesNotPerformAssertions()]
    public function testAllowsSamePathQueryParamsWithDifferentMethods(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action'], ['GET']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action'], ['POST']);

        $this->detector->detectDuplicate($route1);
        $this->detector->detectDuplicate($route2); // Should not throw
    }

    public function testThrowsWhenAnyMethodRouteExistsAndSpecificMethodAdded(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action']); // any method
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action'], ['GET']);

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->detector->detectDuplicate($route2);
    }

    public function testThrowsWhenSpecificMethodExistsAndAnyMethodAdded(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action'], ['GET']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action']); // any method

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->detector->detectDuplicate($route2);
    }

    public function testQueryParamKeysAreOrderIndependent(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action', 'type']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['type', 'action']);

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->detector->detectDuplicate($route2);
    }

    #[DoesNotPerformAssertions()]
    public function testQueryParamKeysAreCaseSensitive(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['Action']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action']);

        $this->detector->detectDuplicate($route1);
        $this->detector->detectDuplicate($route2); // Should not throw (different case)
    }

    public function testThrowsOnDuplicateRouteName(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action'], null, 'api-route');
        $route2 = new QueryParamRoute('/other', $this->middleware, ['type'], null, 'api-route');

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage('name "api-route"');
        $this->detector->detectDuplicate($route2);
    }

    #[DoesNotPerformAssertions()]
    public function testAllowsSamePathDifferentQueryParamCount(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action', 'type']);

        $this->detector->detectDuplicate($route1);
        $this->detector->detectDuplicate($route2); // Should not throw
    }

    #[DoesNotPerformAssertions()]
    public function testAllowsEmptyQueryParamsOnDifferentPaths(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, []);
        $route2 = new QueryParamRoute('/other', $this->middleware, []);

        $this->detector->detectDuplicate($route1);
        $this->detector->detectDuplicate($route2); // Should not throw
    }

    public function testThrowsOnDuplicateEmptyQueryParamsSamePath(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, []);
        $route2 = new QueryParamRoute('/api', $this->middleware, []);

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->detector->detectDuplicate($route2);
    }

    public function testThrowsOnMethodOverlapWithMultipleMethods(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action'], ['GET', 'POST']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action'], ['POST', 'PUT']);

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage('POST');
        $this->detector->detectDuplicate($route2);
    }

    // Value constraint tests

    public function testAllowsSamePathWithDifferentValueConstraints(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action' => 'profile', 'u' => null]);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action' => 'edit', 'u' => null]);

        $this->detector->detectDuplicate($route1);
        $this->detector->detectDuplicate($route2); // Should not throw - different value constraints
        
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testThrowsOnSamePathAndValueConstraints(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action' => 'profile', 'u' => null]);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action' => 'profile', 'u' => null]);

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->detector->detectDuplicate($route2);
    }

    public function testAllowsWildcardAndSpecificValueForSameKey(): void
    {
        // Wildcard route (any value)
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action' => null, 'u' => null]);
        
        // Specific value route
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action' => 'profile', 'u' => null]);

        $this->detector->detectDuplicate($route1);
        $this->detector->detectDuplicate($route2); // Should not throw - different constraints
        
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testValueConstraintsAreOrderIndependent(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action' => 'profile', 'type' => 'user']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['type' => 'user', 'action' => 'profile']);

        $this->detector->detectDuplicate($route1);

        $this->expectException(DuplicateRouteException::class);
        $this->detector->detectDuplicate($route2); // Should throw - same constraints, different order
    }

    public function testMixedConstraintsNotConsideredDuplicate(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action' => 'profile', 'type' => null]);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action' => null, 'type' => 'user']);

        $this->detector->detectDuplicate($route1);
        $this->detector->detectDuplicate($route2); // Should not throw - different constraints
        
        $this->assertTrue(true); // If we get here, no exception was thrown
    }
}
