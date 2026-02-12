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

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Webware\Router\ConfigProvider;
use Webware\Router\QueryParamDuplicateRouteDetector;
use Webware\Router\QueryParamDuplicateRouteDetectorFactory;
use Webware\Router\QueryParamRouter;
use Webware\Router\QueryParamRouterFactory;

class ConfigProviderTest extends TestCase
{
    private ConfigProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ConfigProvider();
    }

    public function testInvokeReturnsArray(): void
    {
        $config = ($this->provider)();

        $this->assertArrayHasKey(QueryParamRouter::class, $config);
    }

    public function testGetDependenciesReturnsDependencyConfiguration(): void
    {
        $dependencies = $this->provider->getDependencies();

        $this->assertArrayHasKey('factories', $dependencies);
        $this->assertArrayHasKey('aliases', $dependencies);
    }

    public function testDependenciesIncludeRouterFactory(): void
    {
        $dependencies = $this->provider->getDependencies();

        $this->assertArrayHasKey(QueryParamRouter::class, $dependencies['factories']);
        $this->assertSame(QueryParamRouterFactory::class, $dependencies['factories'][QueryParamRouter::class]);
    }

    public function testDependenciesIncludeDetectorFactory(): void
    {
        $dependencies = $this->provider->getDependencies();

        $this->assertArrayHasKey(QueryParamDuplicateRouteDetector::class, $dependencies['factories']);
        $this->assertSame(
            QueryParamDuplicateRouteDetectorFactory::class,
            $dependencies['factories'][QueryParamDuplicateRouteDetector::class]
        );
    }

    public function testGetRouterConfigReturnsDefaultConfiguration(): void
    {
        $config = $this->provider->getRouterConfig();

        $this->assertArrayHasKey('detect_duplicates', $config);
        $this->assertTrue($config['detect_duplicates']);
    }

    public function testRouterFactoryCreatesRouterWithDuplicateDetectionEnabled(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            ['config', true],
            [QueryParamDuplicateRouteDetector::class, false],
        ]);
        $container->method('get')->with('config')->willReturn([
            QueryParamRouter::class => ['detect_duplicates' => true],
        ]);

        $factory = new QueryParamRouterFactory();
        $router  = $factory($container);

        $this->assertInstanceOf(QueryParamRouter::class, $router);
    }

    public function testRouterFactoryCreatesRouterWithDuplicateDetectionDisabled(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->with('config')->willReturn([
            QueryParamRouter::class => ['detect_duplicates' => false],
        ]);

        $factory = new QueryParamRouterFactory();
        $router  = $factory($container);

        $this->assertInstanceOf(QueryParamRouter::class, $router);
    }

    public function testRouterFactoryUsesDefaultsWhenNoConfigPresent(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $factory = new QueryParamRouterFactory();
        $router  = $factory($container);

        $this->assertInstanceOf(QueryParamRouter::class, $router);
    }

    public function testDetectorFactoryCreatesDetector(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $factory  = new QueryParamDuplicateRouteDetectorFactory();
        $detector = $factory($container);

        $this->assertInstanceOf(QueryParamDuplicateRouteDetector::class, $detector);
    }
}
