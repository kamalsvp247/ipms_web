export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Permissions = {
    'settings.read': boolean;
    'settings.write': boolean;
    'captcha.read': boolean;
    'captcha.write': boolean;
    'accounts.read': boolean;
    'accounts.write': boolean;
    'proxies.read': boolean;
    'proxies.write': boolean;
    'bot.manage': boolean;
    'notice.write': boolean;
};

export type Auth = {
    user: User;
    permissions: Permissions;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
