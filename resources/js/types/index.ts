export * from './auth';
export * from './bulk';
export * from './calendar';
export * from './discover';
export * from './library';
export * from './navigation';
export * from './preferences';
export * from './ui';
export * from './whisparr';

export type SelectOption<TValue = string, LabelKey extends string = 'label'> = {
    [K in LabelKey]: string;
} & { value: TValue };
export type SelectOptionGroup<
    TValue = string,
    LabelKey extends string = 'label',
> = Record<string, SelectOption<TValue, LabelKey>>;
