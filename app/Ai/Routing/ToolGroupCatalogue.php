<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\Agents\MediaAgent;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Every routable tool group an install can use, searchable by what the user
 * needs, so the chat assistant can find tools that are not loaded this turn.
 * Matching is deterministic word overlap; no model runs.
 */
class ToolGroupCatalogue
{
    private const int MAX_GROUPS = 3;

    private const int MAX_TOOLS_PER_GROUP = 3;

    private const int STEM_LENGTH = 5;

    private const int SUMMARY_LENGTH = 160;

    /**
     * @var list<string>
     */
    private const array STOPWORDS = ['the', 'and', 'for', 'that', 'with', 'this', 'from', 'into', 'which', 'what', 'returns', 'return', 'find', 'get', 'use'];

    /**
     * Groups with at least one tool this install's MediaAgent declares.
     *
     * @return list<ToolGroup>
     */
    public function available(): array
    {
        return $this->availableFrom($this->declaredClasses());
    }

    /**
     * @param  list<class-string>  $declared
     * @return list<ToolGroup>
     */
    private function availableFrom(array $declared): array
    {
        return array_values(array_filter(
            ToolGroup::cases(),
            fn (ToolGroup $toolGroup): bool => $this->declaredToolClasses($toolGroup, $declared) !== [],
        ));
    }

    /**
     * Every tool class this install's MediaAgent declares.
     *
     * @return list<class-string>
     */
    private function declaredClasses(): array
    {
        return array_map(
            static fn (object $tool): string => $tool::class,
            [...(new MediaAgent)->tools()],
        );
    }

    /**
     * The group's tool classes among the declared ones.
     *
     * @param  list<class-string>  $declared
     * @return list<class-string>
     */
    private function declaredToolClasses(ToolGroup $toolGroup, array $declared): array
    {
        return array_values(array_intersect($toolGroup->toolClasses(), $declared));
    }

    /**
     * @return list<array{group: string, label: string, tools: list<array{name: string, summary: string}>}>
     */
    public function search(string $need): array
    {
        $words = $this->words($need);

        if ($words === []) {
            return [];
        }

        $scored = [];

        $declared = $this->declaredClasses();

        foreach ($this->availableFrom($declared) as $toolGroup) {
            $match = $this->scoreGroup($toolGroup, $words, $declared);

            if ($match !== null) {
                $scored[] = $match;
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $match): array => [
            'group' => $match['group'],
            'label' => $match['label'],
            'tools' => $match['tools'],
        ], array_slice($scored, 0, self::MAX_GROUPS));
    }

    /**
     * @param  list<string>  $words
     * @param  list<class-string>  $declared
     * @return array{score: int, group: string, label: string, tools: list<array{name: string, summary: string}>}|null
     */
    private function scoreGroup(ToolGroup $toolGroup, array $words, array $declared): ?array
    {
        $tools = [];

        foreach ($this->declaredToolClasses($toolGroup, $declared) as $class) {
            $tool = resolve($class);
            $description = (string) $tool->description();
            $score = $this->score($words, $this->haystack($tool, $description));

            if ($score > 0) {
                $tools[] = [
                    'score' => $score,
                    'name' => $tool instanceof CanActAsTool ? $tool->name() : ToolNameResolver::resolve($tool),
                    'summary' => $this->summary($description),
                ];
            }
        }

        if ($tools === []) {
            return null;
        }

        usort($tools, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $groupScore = $tools[0]['score']
            + ($this->score($words, strtolower($toolGroup->label().' '.$toolGroup->question())) > 0 ? 1 : 0);

        return [
            'score' => $groupScore,
            'group' => $toolGroup->value,
            'label' => $toolGroup->label(),
            'tools' => array_map(
                static fn (array $tool): array => ['name' => $tool['name'], 'summary' => $tool['summary']],
                array_slice($tools, 0, self::MAX_TOOLS_PER_GROUP),
            ),
        ];
    }

    /**
     * A tool's name and description, plus those of a sub-agent's own tools.
     */
    private function haystack(object $tool, string $description): string
    {
        $text = $this->nameAndDescription($tool, $description);

        if ($tool instanceof CanActAsTool && $tool instanceof HasTools) {
            foreach ($tool->tools() as $inner) {
                $text .= ' '.$this->nameAndDescription($inner, (string) $inner->description());
            }
        }

        return strtolower($text);
    }

    private function nameAndDescription(object $tool, string $description): string
    {
        $name = $tool instanceof Tool ? ToolNameResolver::resolve($tool) : class_basename($tool);

        return sprintf('%s %s', Str::snake($name, ' '), $description);
    }

    /**
     * @param  list<string>  $words
     */
    private function score(array $words, string $haystack): int
    {
        return count(array_filter(
            $words,
            static fn (string $word): bool => str_contains($haystack, $word),
        ));
    }

    /**
     * Distinct need words, long ones cut to a stem so "replacing" finds "replacement".
     *
     * @return list<string>
     */
    private function words(string $need): array
    {
        $words = preg_split('/[^a-z]+/', strtolower($need), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = array_filter(
            $words,
            static fn (string $word): bool => strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true),
        );

        return array_values(array_unique(array_map(
            static fn (string $word): string => strlen($word) >= 6 ? substr($word, 0, self::STEM_LENGTH) : $word,
            $words,
        )));
    }

    private function summary(string $description): string
    {
        $sentence = preg_split('/(?<=[.!?])\s/', trim($description), 2)[0];

        return Str::limit($sentence, self::SUMMARY_LENGTH);
    }
}
