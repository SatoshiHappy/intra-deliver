import { usePage } from '@inertiajs/react';
import { AppLogoIcon } from '@/components/AppLogoIcon';

/**
 * ヘッダーの印と製品名(docs の 設計/画面の意匠/骨格とヘッダー.md 3 節)。印は 32px の地に 20px の AppLogoIcon(正本は resources/brand/mark.svg)、製品名は共有プロパティ name(APP_NAME)。
 * 会社名は出さない。
 */
export function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary text-secondary-foreground">
                <AppLogoIcon className="size-5" />
            </div>
            <span className="text-base font-bold whitespace-nowrap">
                {name}
            </span>
        </>
    );
}
