import type { ReactNode } from 'react';
import { TipButton } from '@/components/TipButton';

/**
 * 面(カード)・区画の見出しの横の「?」({@link TipButton} の説明。docs の 設計/画面の意匠/README.md 方針 9(d))。
 * 見出しの要素(h2 など)の外に置き(面の名前 = aria-labelledby の見出しを変えない)、呼ぶ側が見出しと横に並べる外側の要素で包む。
 * 文の写しは置かない(ボタンの読み上げで足りる)。label = 見出しの文、children = 説明の文。
 */
export function HeadingHelp({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <TipButton kind="help" name={label}>
            {children}
        </TipButton>
    );
}
