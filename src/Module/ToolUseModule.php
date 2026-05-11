<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Module;

use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolCallObserverInterface;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use BEAR\ToolUse\Schema\JsonSchemaRepository;
use BEAR\ToolUse\Schema\JsonSchemaRepositoryInterface;
use BEAR\ToolUse\Schema\ParameterDescriptionResolver;
use BEAR\ToolUse\Schema\ParameterDescriptionResolverInterface;
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
 *
 * BEAR.Resource's FactoryInterface is expected to be bound by the application
 * via BEAR.Sunday's ResourceModule.
 *
 * For JSON Schema support, install BEAR\Resource\Module\JsonSchemaModule
 * which provides the 'json_validate_dir' binding.
 */
final class ToolUseModule extends AbstractModule
{
    #[Override]
    protected function configure(): void
    {
        // DocBlock Factory
        $this->bind(DocBlockFactoryInterface::class)->toInstance(DocBlockFactory::createInstance());

        // Tool Registry (singleton to share across components)
        $this->bind(ToolRegistryInterface::class)->to(ToolRegistry::class)->in(Scope::SINGLETON);

        // Parameter Description Resolver
        $this->bind(ParameterDescriptionResolverInterface::class)->to(ParameterDescriptionResolver::class);

        // Schema Converter
        $this->bind(SchemaConverterInterface::class)->to(SchemaConverter::class);

        // Tool Collector
        $this->bind(ToolCollectorInterface::class)->to(ToolCollector::class);

        // Tool Call Observer (default no-op; users can override with their own implementation)
        $this->bind(ToolCallObserverInterface::class)->to(NullToolCallObserver::class);

        // Dispatcher
        $this->bind(DispatcherInterface::class)->to(Dispatcher::class);

        // JSON Schema Repository (optional - requires json_validate_dir binding)
        $this->bind(JsonSchemaRepositoryInterface::class)
            ->toConstructor(
                JsonSchemaRepository::class,
                ['validateDir' => 'json_validate_dir'],
            );
    }
}
