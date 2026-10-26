import type { AiTask } from '@/typefinder';
import type { EventOverrideHooks } from './types';

/**
 * The `data-*` hooks of a task's event-override block. Decision keeps the
 * unscoped legacy names (`data-event-override`, …): existing browser tests
 * select them without naming a task. Every other task prefixes its own, so a
 * selector never matches two blocks and trips Playwright's strict mode.
 */
export function eventOverrideHooks(task: AiTask): EventOverrideHooks {
    const prefix =
        task === 'decision' ? 'data-' : `data-${task.replace(/_/g, '-')}-`;

    return {
        override: `${prefix}event-override`,
        add: `${prefix}add-event-override`,
        option: `${prefix}event-option`,
        remove: `${prefix}remove-override`,
        resolved: `${prefix}override-resolved`,
        warning: `${prefix}override-warning`,
    };
}
