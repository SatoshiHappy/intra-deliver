// フォントは npm の Fontsource の可変フォントを自前で配る(docs の 設計/画面の意匠/利用者メニューとフォント.md 7 節)
import '@fontsource-variable/noto-sans-jp';
import '@fontsource-variable/inter';
import { createInertiaApp } from '@inertiajs/react';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/AppLayout';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'イントラデリバー';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: () => AppLayout,
    strictMode: true,
    progress: {
        // 読み込みの帯の色 = --primary(変数のまま CSS に入るので、ダークにも追従する。docs の 設計/画面の意匠/骨格とヘッダー.md 2 節)
        color: 'var(--primary)',
    },
});

// This will listen for flash toast data from the server...
// (テーマの当て込みより先に結ぶ。起動の途中で止まっても、flash のトーストは出る)
initializeFlashToast();

// This will set light / dark mode on page load...
initializeTheme();
