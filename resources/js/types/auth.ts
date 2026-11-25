import type {
    Ability,
    SharedPageProps,
    SharedUserResource,
} from '@/typefinder';

export type { Ability };

export type User = SharedUserResource;

/**
 * The shared `auth` prop, narrowed in two places. `user` is null only for
 * guests, and the layouts that read it render for signed-in users only.
 * `can` is keyed by Ability, which the generated Record<string, boolean>
 * loses (https://github.com/pentacore/Laravel-Typefinder/issues/236).
 */
export type Auth = SharedPageProps['auth'] & {
    user: User;
    can: Record<Ability, boolean>;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
