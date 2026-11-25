import type {
    Ability,
    SharedPageProps,
    SharedUserResource,
} from '@/typefinder';

export type { Ability };

export type User = SharedUserResource;

/**
 * The shared `auth` prop with `user` narrowed to non-null: it is null only
 * for guests, and the layouts that read it render for signed-in users only.
 */
export type Auth = SharedPageProps['auth'] & {
    user: User;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
