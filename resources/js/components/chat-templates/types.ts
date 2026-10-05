import type { ChatTemplateResource } from '@/typefinder';

export type ChatTemplate = ChatTemplateResource;

export type ChatTemplateVariable = ChatTemplateResource['variables'][number];

export type ChatTemplateVariableKind = ChatTemplateVariable['type'];

export interface VariableTypeOption {
    value: ChatTemplateVariableKind;
    label: string;
}

export interface PreviewSegment {
    kind: 'text' | 'placeholder';
    value: string;
}

export interface PreviewResponse {
    segments: PreviewSegment[];
    errors: Record<string, string[]>;
}

export interface LibraryHit {
    id: number;
    title: string;
    year: number | null;
    poster_url: string | null;
}

/** What happens with rendered template text. */
export type TemplateAction = 'insert' | 'send';
