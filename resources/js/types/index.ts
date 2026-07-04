export * from './auth';
export * from './navigation';
export * from './preferences';
export * from './ui';

export type SelectOption<TValue = string, LabelKey extends string = 'label'> = {
    [K in LabelKey]: string;
} & { value: TValue };
export type SelectOptionGroup<
    TValue = string,
    LabelKey extends string = 'label',
> = Record<string, SelectOption<TValue, LabelKey>>;
