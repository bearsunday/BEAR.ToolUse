<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Module;

use BEAR\Resource\Module\ResourceModule;
use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use BEAR\ToolUse\Schema\SchemaConverterInterface;
use BEAR\ToolUse\Schema\ToolCollectorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

#[CoversClass(ToolUseModule::class)]
final class ToolUseModuleTest extends TestCase
{
    public function testBindings(): void
    {
        $injector = new Injector(new ToolUseModule('app', new ResourceModule('BEAR\ToolUse\Fake')));

        $this->assertInstanceOf(
            ToolRegistryInterface::class,
            $injector->getInstance(ToolRegistryInterface::class),
        );
        $this->assertInstanceOf(
            SchemaConverterInterface::class,
            $injector->getInstance(SchemaConverterInterface::class),
        );
        $this->assertInstanceOf(
            ToolCollectorInterface::class,
            $injector->getInstance(ToolCollectorInterface::class),
        );
        $this->assertInstanceOf(
            DispatcherInterface::class,
            $injector->getInstance(DispatcherInterface::class),
        );
    }

    public function testRegistrySingleton(): void
    {
        $injector = new Injector(new ToolUseModule('app', new ResourceModule('BEAR\ToolUse\Fake')));

        $registry1 = $injector->getInstance(ToolRegistryInterface::class);
        $registry2 = $injector->getInstance(ToolRegistryInterface::class);

        $this->assertSame($registry1, $registry2);
    }

    public function testCustomScheme(): void
    {
        $injector = new Injector(new ToolUseModule('page', new ResourceModule('BEAR\ToolUse\Fake')));

        $dispatcher = $injector->getInstance(DispatcherInterface::class);
        $this->assertInstanceOf(DispatcherInterface::class, $dispatcher);
    }
}
