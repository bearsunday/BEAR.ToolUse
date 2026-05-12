<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Runtime\InputProcessorInterface;
use BEAR\ToolUse\Runtime\LlmRequest;
use BEAR\ToolUse\Schema\Tool;
use Override;

/**
 * Fake tool filtering input processor for testing
 */
final readonly class FakeToolFilteringInputProcessor implements InputProcessorInterface
{
    public function __construct(
        private string $enabledTool,
    ) {
    }

    #[Override]
    public function process(LlmRequest $request): LlmRequest
    {
        $tools = [];
        foreach ($request->tools as $tool) {
            if ($tool->name !== $this->enabledTool) {
                continue;
            }

            $tools[] = $tool;
        }

        /** @var list<Tool> $tools */
        return $request->withTools($tools);
    }
}
