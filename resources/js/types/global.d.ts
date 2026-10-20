import type { CurrentAccount, UserMenuSection } from './account';
import type { MenuGroup } from './menu';
import type { NoticeSummary } from './notice';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            sidebarOpen: boolean;
            globalNavigation: MenuGroup[];
            account: CurrentAccount;
            userMenuSections: UserMenuSection[];
            noticeSummary: NoticeSummary | null;
            [key: string]: unknown;
        };
    }
}
