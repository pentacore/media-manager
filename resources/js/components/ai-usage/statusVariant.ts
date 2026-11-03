/**
 * The pill variant for an AI usage run or tool invocation status.
 */
export function usageStatusVariant(status: string): 'ok' | 'warn' | 'danger' {
    if (status === 'success') {
        return 'ok';
    }

    return status === 'stopped' ? 'warn' : 'danger';
}
