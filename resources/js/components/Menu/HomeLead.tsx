import { HomeActionButton } from '@/components/Menu/HomeActionButton';
import type { HomeLeadSection } from '@/types';

/**
 * あいさつ欄の一文と主ボタン(docs の 設計/メニュー/ホーム.md 6.3。見出し h1 と日付は ページ Menu/Home が描く)。
 */
export function HomeLead({ section }: { section: HomeLeadSection }) {
    return (
        <div id={section.code}>
            <p className="max-w-[46em] text-muted-foreground">{section.text}</p>
            {section.action !== null && (
                <div className="mt-3.5">
                    <HomeActionButton action={section.action} />
                </div>
            )}
        </div>
    );
}
