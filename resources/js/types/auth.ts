import type { Ability, SharedUserResource } from '@/typefinder';

export type { Ability };

export type User = SharedUserResource;

export type Auth = {
    user: User;
    can: Record<Ability, boolean>;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
