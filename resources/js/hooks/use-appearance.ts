import type { Appearance } from '@/types';

function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

// 保存した外観の読み方を DOM なしで確かめられるように export する
export const getStoredAppearance = (): Appearance => {
    if (typeof window === 'undefined') {
        return 'system';
    }

    try {
        return (
            (localStorage.getItem('appearance') as Appearance | null) ||
            'system'
        );
    } catch {
        // 保存先が使えない(無効にされた・容量の外など)ときはシステムの設定に追従する(起動を止めない)
        return 'system';
    }
};

// システムの設定が変わったら、保存した値(system のときだけ効く)で当て直す
const handleSystemThemeChange = () => {
    updateTheme(getStoredAppearance());
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to system...
    updateTheme(getStoredAppearance());

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}
