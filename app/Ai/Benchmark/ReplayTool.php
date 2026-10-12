<?php

declare(strict_types=1);

namespace App\Ai\Benchmark;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\AiMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

/**
 * Stands in for a tool inside a benchmark step: the model sees the inner
 * tool's name, description and schema, but the inner tool never runs. A read
 * call answers its recorded fixture (or the miss result, counted); a write
 * call is captured as an intended action and answers its recorded result or
 * the canned queued result, without counting a miss.
 */
final readonly class ReplayTool implements Tool
{
    /** What a write call answers when the case recorded no result for it. */
    public const string QUEUED_RESULT = '{"queued":true,"status":"queued","benchmark":true}';

    public function __construct(
        private Tool $inner,
        private bool $writes,
        private FixtureBook $fixtureBook,
        private ?AiMode $aiMode,
    ) {}

    public function name(): string
    {
        return ToolNameResolver::resolve($this->inner);
    }

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    public function handle(Request $request): string
    {
        $name = $this->name();
        $arguments = $request->toArray();

        if (! $this->writes) {
            return $this->fixtureBook->answer($name, $arguments) ?? FixtureBook::MISS_RESULT;
        }

        if ($this->inner instanceof BaseTool && $this->inner->risk() === Risk::Destructive && $this->aiMode === AiMode::Advisory) {
            return BaseTool::ADVISORY_REFUSAL;
        }

        $this->fixtureBook->intend($name, $arguments);

        return $this->fixtureBook->peek($name, $arguments) ?? self::QUEUED_RESULT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function inner(): Tool
    {
        return $this->inner;
    }
}
