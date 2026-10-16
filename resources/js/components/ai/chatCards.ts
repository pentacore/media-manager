import type { ChatCard, ChoiceOption, ChoicesCard } from './types';

/** The SDK's name for `App\Ai\Tools\System\OfferChoicesTool`. */
export const CHOICES_TOOL_NAME = 'OfferChoicesTool';

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function choicesCard(
    id: string,
    choices: Record<string, unknown>,
): ChoicesCard {
    const options = Array.isArray(choices.options) ? choices.options : [];

    return {
        type: 'choices',
        id,
        question: String(choices.question ?? ''),
        multiple: Boolean(choices.multiple ?? false),
        options: options.filter(isRecord).map((option): ChoiceOption => ({
            label: String(option.label ?? ''),
            description:
                typeof option.description === 'string'
                    ? option.description
                    : null,
        })),
    };
}

/**
 * The card one tool result shows under the reply, or null. Mirrors
 * `ChatCardExtractor::fromToolResults()` for a single result: an
 * OfferChoicesTool result with a `choices` object becomes a choices card
 * (keyed by its tool call id), a queued action becomes an approval card.
 */
export function cardFromToolResult(
    content: unknown,
    toolCallId: string,
    toolName: string,
): ChatCard | null {
    let result = content;

    if (typeof result === 'string') {
        try {
            result = JSON.parse(result) as unknown;
        } catch {
            return null;
        }
    }

    if (!isRecord(result)) {
        return null;
    }

    if (toolName === CHOICES_TOOL_NAME && isRecord(result.choices)) {
        return choicesCard(toolCallId, result.choices);
    }

    if (result.queued === true && Number.isInteger(result.action_request_id)) {
        return {
            type: 'action_request',
            id: result.action_request_id as number,
            title: typeof result.title === 'string' ? result.title : null,
            status: String(result.status ?? ''),
            requires_approval: Boolean(result.requires_approval ?? false),
        };
    }

    return null;
}

/**
 * The labels a reloaded reply's choices were answered with: the next user
 * message when it is one offered label, else that message split on ", "
 * (how a multi pick is sent), kept only where it names an offered option.
 */
export function inferChosenLabels(cards: ChatCard[], answer: string): string[] {
    const labels = new Set(
        cards.flatMap((card) =>
            card.type === 'choices'
                ? card.options.map((option) => option.label)
                : [],
        ),
    );

    const whole = answer.trim();

    // A label may itself hold ", " ("Dune, Part Two").
    if (labels.has(whole)) {
        return [whole];
    }

    return whole
        .split(', ')
        .map((part) => part.trim())
        .filter((part) => labels.has(part));
}
