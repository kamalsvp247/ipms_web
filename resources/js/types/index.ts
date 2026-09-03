export * from './auth';
export * from './navigation';
export * from './ui';

import type { Auth } from './auth';

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    /** Enabled header notices (Bengali), in display order — empty when there are none. */
    notices: string[];
    [key: string]: unknown;
};
