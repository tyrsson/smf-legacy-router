<?php

declare(strict_types=1);

namespace WebwareTest\Router;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Mezzio\Router\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;
use Webware\Router\QueryParamDuplicateRouteDetector;
use Webware\Router\QueryParamRoute;
use Webware\Router\QueryParamRouter;

class QueryParamRouterTest extends TestCase
{
    private QueryParamRouter $router;
    private MiddlewareInterface $middleware;

    protected function setUp(): void
    {
        $this->router = new QueryParamRouter();
        $this->middleware = $this->createMock(MiddlewareInterface::class);
    }

    public function testImplementsRouterInterface(): void
    {
        $this->assertInstanceOf(\Mezzio\Router\RouterInterface::class, $this->router);
    }

    public function testAddRouteStoresRoute(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action']);
        $this->router->addRoute($route);

        // If no exception thrown, route was added successfully
        $this->assertTrue(true);
    }

    public function testMatchReturnsSuccessForMatchingPathAndQueryParams(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action']);
        $this->router->addRoute($route);

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['action' => 'create']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($route, $result->getMatchedRoute());
    }

    public function testMatchReturnsFailureForMissingQueryParam(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action']);
        $this->router->addRoute($route);

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['other' => 'value']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isSuccess());
    }

    public function testMatchReturnsFailureForNonMatchingPath(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action']);
        $this->router->addRoute($route);

        $request = new ServerRequest([], [], '/other', 'GET');
        $request = $request->withQueryParams(['action' => 'create']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isFailure());
    }

    public function testMatchIncludesQueryParamValuesInMatchedParams(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action', 'type']);
        $this->router->addRoute($route);

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['action' => 'create', 'type' => 'user']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isSuccess());
        $matchedParams = $result->getMatchedParams();
        $this->assertSame('create', $matchedParams['action']);
        $this->assertSame('user', $matchedParams['type']);
    }

    public function testMatchExcludesNonRequiredQueryParams(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action']);
        $this->router->addRoute($route);

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['action' => 'create', 'extra' => 'value']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isSuccess());
        $matchedParams = $result->getMatchedParams();
        $this->assertSame('create', $matchedParams['action']);
        $this->assertArrayNotHasKey('extra', $matchedParams);
    }

    public function testMatchRespectsHttpMethodWhenSpecified(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action'], ['POST']);
        $this->router->addRoute($route);

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['action' => 'create']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isFailure());
    }

    public function testMatchAllowsAnyMethodWhenNotSpecified(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action']);
        $this->router->addRoute($route);

        $getRequest = new ServerRequest([], [], '/api', 'GET');
        $getRequest = $getRequest->withQueryParams(['action' => 'create']);

        $postRequest = new ServerRequest([], [], '/api', 'POST');
        $postRequest = $postRequest->withQueryParams(['action' => 'create']);

        $this->assertTrue($this->router->match($getRequest)->isSuccess());
        $this->assertTrue($this->router->match($postRequest)->isSuccess());
    }

    public function testMatchSelectsMostSpecificRoute(): void
    {
        $route1 = new QueryParamRoute('/api', $this->middleware, ['action']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action', 'type']);

        $this->router->addRoute($route1);
        $this->router->addRoute($route2);

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['action' => 'create', 'type' => 'user']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($route2, $result->getMatchedRoute());
    }

    public function testMatchWithoutQueryParamsMatchesEmptyQueryRoute(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, []);
        $this->router->addRoute($route);

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams([]);

        $result = $this->router->match($request);

        $this->assertTrue($result->isSuccess());
    }

    public function testGenerateUriReturnsPathForBasicRoute(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action'], null, 'api-route');
        $this->router->addRoute($route);

        $uri = $this->router->generateUri('api-route');

        $this->assertSame('/api', $uri);
    }

    public function testGenerateUriIncludesQueryParamsFromOptions(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['action'], null, 'api-route');
        $this->router->addRoute($route);

        $uri = $this->router->generateUri('api-route', [], ['query' => ['action' => 'create', 'type' => 'user']]);

        $this->assertStringContainsString('/api?', $uri);
        $this->assertStringContainsString('action=create', $uri);
        $this->assertStringContainsString('type=user', $uri);
    }

    public function testGenerateUriThrowsForUnknownRoute(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot generate URI');

        $this->router->generateUri('unknown-route');
    }

    public function testGenerateUriHandlesSubstitutions(): void
    {
        $route = new QueryParamRoute('/users/{id}', $this->middleware, ['action'], null, 'user-route');
        $this->router->addRoute($route);

        $uri = $this->router->generateUri('user-route', ['id' => '123']);

        $this->assertStringContainsString('/users/123', $uri);
    }

    public function testGenerateUriCombinesSubstitutionsAndQueryParams(): void
    {
        $route = new QueryParamRoute('/users/{id}', $this->middleware, ['action'], null, 'user-route');
        $this->router->addRoute($route);

        $uri = $this->router->generateUri('user-route', ['id' => '123'], ['query' => ['action' => 'edit']]);

        $this->assertStringContainsString('/users/123', $uri);
        $this->assertStringContainsString('action=edit', $uri);
    }

    public function testRouterWithDuplicateDetector(): void
    {
        $detector = new QueryParamDuplicateRouteDetector();
        $router = new QueryParamRouter($detector);

        $route1 = new QueryParamRoute('/api', $this->middleware, ['action']);
        $router->addRoute($route1);

        // This should throw due to duplicate detection
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action']);

        $this->expectException(\Mezzio\Router\Exception\DuplicateRouteException::class);
        $router->addRoute($route2);
    }

    public function testRouterWithoutDuplicateDetectorAllowsDuplicates(): void
    {
        $router = new QueryParamRouter(null);

        $route1 = new QueryParamRoute('/api', $this->middleware, ['action']);
        $route2 = new QueryParamRoute('/api', $this->middleware, ['action']);

        $router->addRoute($route1);
        $router->addRoute($route2); // Should not throw

        $this->assertTrue(true);
    }

    public function testMatchQueryParamKeysAreCaseSensitive(): void
    {
        $route = new QueryParamRoute('/api', $this->middleware, ['Action']);
        $this->router->addRoute($route);

        $request1 = new ServerRequest([], [], '/api', 'GET');
        $request1 = $request1->withQueryParams(['Action' => 'create']);

        $request2 = new ServerRequest([], [], '/api', 'GET');
        $request2 = $request2->withQueryParams(['action' => 'create']);

        $this->assertTrue($this->router->match($request1)->isSuccess());
        $this->assertTrue($this->router->match($request2)->isFailure());
    }
}
