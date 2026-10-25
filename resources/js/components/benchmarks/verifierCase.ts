import {
    EVENT_PAYLOAD_MESSAGES,
    parseJsonObject,
    PAYLOAD_MESSAGES,
} from './jsonObject';
import type {
    BenchmarkVerifierActionType,
    BenchmarkVerifierForm,
    BenchmarkVerifierInput,
} from './types';

/** The editable verifier case: a saved one as stored, a new one from the first action type. */
export function verifierFormState(
    input: BenchmarkVerifierInput | null | undefined,
    defaults: { actionType?: BenchmarkVerifierActionType; service: string },
): BenchmarkVerifierForm {
    if (input) {
        return {
            ...input,
            details: input.details.map((detail) => ({ ...detail })),
            event_type: input.event_type ?? '',
        };
    }

    return {
        action_type: defaults.actionType?.value ?? '',
        action_label: defaults.actionType?.label ?? '',
        title: '',
        description: '',
        details: [],
        verified: true,
        payload_json: '{}',
        rationale: '',
        source_service: defaults.service,
        event_type: '',
        event_payload_json: '{}',
    };
}

/** The verifier case as the server takes it: blank payloads are `{}`, a blank event type is null. */
export function verifierBody(
    form: BenchmarkVerifierForm,
): BenchmarkVerifierInput {
    return {
        ...form,
        details: form.details.map(({ label, value }) => ({ label, value })),
        payload_json:
            form.payload_json.trim() === '' ? '{}' : form.payload_json,
        event_type: form.event_type.trim() === '' ? null : form.event_type,
        event_payload_json:
            form.event_payload_json.trim() === ''
                ? '{}'
                : form.event_payload_json,
    };
}

/** True when either payload is not a JSON object. */
export function verifierPayloadsInvalid(form: BenchmarkVerifierForm): boolean {
    return (
        parseJsonObject(form.payload_json, PAYLOAD_MESSAGES).error !== null ||
        parseJsonObject(form.event_payload_json, EVENT_PAYLOAD_MESSAGES)
            .error !== null
    );
}
