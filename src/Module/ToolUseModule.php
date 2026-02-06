<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Module;

use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use BEAR\ToolUse\Schema\SchemaConverter;
use BEAR\ToolUse\Schema\SchemaConverterInterface;
use BEAR\ToolUse\Schema\ToolCollector;
use BEAR\ToolUse\Schema\ToolCollectorInterface;
use Override;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * DI module for BEAR.ToolUse
 *
 * Note: LlmClientInterface must be bound by the application.
 * This module does not provide a default LLM implementation.
 */
final class ToolUseModule extends AbstractModule
{
    public function __construct(
        private readonly string $scheme = 'app',
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    #[Override]
    protected function configure(): void
    {
        // DocBlock Factory
        $this->bind(DocBlockFactoryInterface::class)->toInstance(DocBlockFactory::createInstance());

        // Tool Registry (singleton to share across components)
        $this->bind(ToolRegistryInterface::class)->to(ToolRegistry::class)->in(Scope::SINGLETON);

        // Schema Converter
        $this->bind(SchemaConverterInterface::class)->to(SchemaConverter::class);

        // Tool Collector
        $this->bind(ToolCollectorInterface::class)->to(ToolCollector::class);

        // Dispatcher
        $this->bind(DispatcherInterface::class)
            ->toConstructor(
                Dispatcher::class,
                ['scheme' => 'dispatcher_scheme'],
            );
        $this->bind()->annotatedWith('dispatcher_scheme')->toInstance($this->scheme);
    }
}
