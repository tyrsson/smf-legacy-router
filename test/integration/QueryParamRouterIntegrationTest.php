<?php

declare(strict_types=1);

namespace WebwareIntegrationTest\Router;

use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Stratigility\MiddlewarePipe;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Webware\Router\QueryParamDuplicateRouteDetector;
use Webware\Router\QueryParamRoute;
use Webware\Router\QueryParamRouter;
use Mezzio\Router\RouteResult;

class QueryParamRouterIntegrationTest extends TestCase
{
    private QueryParamRouter $router;

    protected function setUp(): void
    {
        $this->router = new QueryParamRouter(new QueryParamDuplicateRouteDetector());
    }

    public function testCompleteRoutingWorkflowWithMiddlewarePipe(): void
    {
        // Set up routes
        $createHandler = $this->createHandler('create-response');
        $listHandler = $this->createHandler('list-response');

        $createRoute = new QueryParamRoute('/api', $createHandler, ['action'], ['POST'], 'api-create');
        $listRoute = new QueryParamRoute('/api', $listHandler, ['action'], ['GET'], 'api-list');

        $this->router->addRoute($createRoute);
        $this->router->addRoute($listRoute);

        // Create middleware pipeline
        $pipe = new MiddlewarePipe();

        // Add routing middleware
        $pipe->pipe($this->createRoutingMiddleware());

        // Add dispatch middleware
        $pipe->pipe($this->createDispatchMiddleware());

        // Test POST request with action=create
        $request = new ServerRequest([], [], '/api', 'POST');
        $request = $request->withQueryParams(['action' => 'create']);

        $response = $pipe->process($request, $this->createFinalHandler());

        $this->assertSame('create-response', (string) $response->getBody());

        // Test GET request with action=list
        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['action' => 'list']);

        $response = $pipe->process($request, $this->createFinalHandler());

        $this->assertSame('list-response', (string) $response->getBody());
    }

    public function testRouteResultAttributeIsSetInRequest(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $routeResult = $request->getAttribute(RouteResult::class);

                if ($routeResult instanceof RouteResult && $routeResult->isSuccess()) {
                    $params = $routeResult->getMatchedParams();
                    return new TextResponse('action:' . ($params['action'] ?? 'none'));
                }

                return new TextResponse('no-route');
            }
        };

        $route = new QueryParamRoute('/api', $middleware, ['action']);
        $this->router->addRoute($route);

        // Create pipeline
        $pipe = new MiddlewarePipe();
        $pipe->pipe($this->createRoutingMiddleware());
        $pipe->pipe($this->createDispatchMiddleware());

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['action' => 'create']);

        $response = $pipe->process($request, $this->createFinalHandler());

        $this->assertSame('action:create', (string) $response->getBody());
    }

    public function testMultipleQueryParamsAreMatched(): void
    {
        $middleware = $this->createHandler('matched');
        $route = new QueryParamRoute('/api', $middleware, ['action', 'type', 'id']);

        $this->router->addRoute($route);

        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams([
            'action' => 'update',
            'type' => 'user',
            'id' => '123',
            'extra' => 'ignored',
        ]);

        $result = $this->router->match($request);

        $this->assertTrue($result->isSuccess());
        $params = $result->getMatchedParams();
        $this->assertSame('update', $params['action']);
        $this->assertSame('user', $params['type']);
        $this->assertSame('123', $params['id']);
        $this->assertArrayNotHasKey('extra', $params);
    }

    public function testRoutePriorityWithMultipleMatchingRoutes(): void
    {
        $handler1 = $this->createHandler('handler1');
        $handler2 = $this->createHandler('handler2');
        $handler3 = $this->createHandler('handler3');

        // Add routes with different specificity
        $route1 = new QueryParamRoute('/api', $handler1, ['action']);
        $route2 = new QueryParamRoute('/api', $handler2, ['action', 'type']);
        $route3 = new QueryParamRoute('/api', $handler3, ['action', 'type', 'id']);

        $this->router->addRoute($route1);
        $this->router->addRoute($route2);
        $this->router->addRoute($route3);

        // Request with all three params should match the most specific route
        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams([
            'action' => 'update',
            'type' => 'user',
            'id' => '123',
        ]);

        $result = $this->router->match($request);
        $this->assertTrue($result->isSuccess());
        $this->assertSame($route3, $result->getMatchedRoute());

        // Request with two params should match the second route
        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams([
            'action' => 'list',
            'type' => 'user',
        ]);

        $result = $this->router->match($request);
        $this->assertTrue($result->isSuccess());
        $this->assertSame($route2, $result->getMatchedRoute());

        // Request with one param should match the first route
        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['action' => 'list']);

        $result = $this->router->match($request);
        $this->assertTrue($result->isSuccess());
        $this->assertSame($route1, $result->getMatchedRoute());
    }

    public function testUriGenerationInRealWorldScenario(): void
    {
        $middleware = $this->createHandler('response');
        $route = new QueryParamRoute('/users/{id}/posts', $middleware, ['action'], null, 'user-posts');

        $this->router->addRoute($route);

        // Generate URI with path substitution and query params
        $uri = $this->router->generateUri(
            'user-posts',
            ['id' => '42'],
            ['query' => ['action' => 'edit', 'draft' => 'true']]
        );

        $this->assertStringContainsString('/users/42/posts', $uri);
        $this->assertStringContainsString('action=edit', $uri);
        $this->assertStringContainsString('draft=true', $uri);
    }

    public function testFailureScenarioReturns404(): void
    {
        $middleware = $this->createHandler('success');
        $route = new QueryParamRoute('/api', $middleware, ['action']);

        $this->router->addRoute($route);

        // Request missing required query param
        $request = new ServerRequest([], [], '/api', 'GET');
        $request = $request->withQueryParams(['other' => 'value']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isSuccess());
    }

    public function testEmptyQueryParamsRouteMatchesAnyQueryParams(): void
    {
        $middleware = $this->createHandler('matched');
        $route = new QueryParamRoute('/api', $middleware, []);

        $this->router->addRoute($route);

        // Should match with no query params
        $request1 = new ServerRequest([], [], '/api', 'GET');
        $result1 = $this->router->match($request1);
        $this->assertTrue($result1->isSuccess());

        // Should also match with any query params
        $request2 = new ServerRequest([], [], '/api', 'GET');
        $request2 = $request2->withQueryParams(['anything' => 'goes']);
        $result2 = $this->router->match($request2);
        $this->assertTrue($result2->isSuccess());
    }

    public function testRouteMatchesWithKeyPresentRegardlessOfValue(): void
    {
        $middleware = $this->createHandler('matched');
        $route = new QueryParamRoute('/', $middleware, ['board']);

        $this->router->addRoute($route);

        // Route should match when 'board' key is present with any value
        $request = new ServerRequest([], [], '/', 'GET');
        $request = $request->withQueryParams(['board' => '100.2']);

        $result = $this->router->match($request);

        $this->assertTrue($result->isSuccess());
        $params = $result->getMatchedParams();
        $this->assertSame('100.2', $params['board']);
    }

    // Helper methods

    private function createHandler(string $responseText): MiddlewareInterface
    {
        return new class($responseText) implements MiddlewareInterface {
            public function __construct(private string $responseText)
            {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                return new TextResponse($this->responseText);
            }
        };
    }

    private function createRoutingMiddleware(): MiddlewareInterface
    {
        $router = $this->router;

        return new class($router) implements MiddlewareInterface {
            public function __construct(private QueryParamRouter $router)
            {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $result = $this->router->match($request);
                $request = $request->withAttribute(RouteResult::class, $result);

                return $handler->handle($request);
            }
        };
    }

    private function createDispatchMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $result = $request->getAttribute(RouteResult::class);

                if ($result instanceof RouteResult && $result->isSuccess()) {
                    $route = $result->getMatchedRoute();
                    return $route->process($request, $handler);
                }

                return $handler->handle($request);
            }
        };
    }

    private function createFinalHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('not-found', 404);
            }
        };
    }
}
