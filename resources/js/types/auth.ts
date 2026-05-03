import type { UserPreferences } from './preferences';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: { value: string } | string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    preferences?: UserPreferences;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
