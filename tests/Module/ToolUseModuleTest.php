<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Module;

use BEAR\Resource\Module\JsonSchemaModule as ResourceJsonSchemaModule;
use BEAR\Resource\Module\ResourceModule;
use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use BEAR\ToolUse\Schema\JsonSchemaRepositoryInterface;
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
        $injector = new Injector(new ToolUseModule(new ResourceModule('BEAR\ToolUse\Fake')));

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
        $injector = new Injector(new ToolUseModule(new ResourceModule('BEAR\ToolUse\Fake')));

        $registry1 = $injector->getInstance(ToolRegistryInterface::class);
        $registry2 = $injector->getInstance(ToolRegistryInterface::class);

        $this->assertSame($registry1, $registry2);
    }

    public function testWithJsonSchemaSupport(): void
    {
        // Uses BEAR\Resource\Module\JsonSchemaModule for 'json_validate_dir' binding
        $injector = new Injector(
            new ToolUseModule(
                new ResourceJsonSchemaModule(
                    '',  // json_schema_dir (for response)
                    __DIR__ . '/../Fake/json_schema',  // json_validate_dir (for input params)
                    new ResourceModule('BEAR\ToolUse\Fake'),
                ),
            ),
        );

        $this->assertInstanceOf(
            JsonSchemaRepositoryInterface::class,
            $injector->getInstance(JsonSchemaRepositoryInterface::class),
        );
    }
}
