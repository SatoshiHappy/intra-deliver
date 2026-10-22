import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    headingIdsContaining,
    isHeadingGroup,
    toggleClosed,
    withOpened,
} from '@/lib/recordHeadingGroups';
import { cn } from '@/lib/utils';
import type { RecordHeadingGroup } from '@/types';

type Props = {
    /** 見出しのまとまり(サーバーの並びの順。頭のまとまりは id・name が null) */
    groups: RecordHeadingGroup[];
    /** まとまりの中身(詳細 = 項目の格子、フォーム = 欄の格子)。畳んでも消さず、隠すだけ(畳んだ中の欄の値も送る) */
    children: (group: RecordHeadingGroup) => ReactNode;
    /** ids の元(1 つのページに 2 つ置いても id が重ならないようにする) */
    idPrefix: string;
    /**
     * 保存の誤りの合図(フォーム)。request が変わるたびに、codes の項目を含む見出しを開く(誤りのない見出しは今の開閉のまま)。
     * 開く状態は描画の中で変えるので、呼び手が同じ更新で最初の誤りの欄へフォーカスを移しても、欄は開いた後に描かれている。
     */
    reveal?: { request: number; codes: string[] };
};

/**
 * 見出しのまとまり 1 つ(見出しの行と、畳める中身。畳んでも中身は消さず隠すだけ)。
 */
function HeadingSection({
    group,
    isOpen,
    bodyId,
    onToggle,
    children,
}: {
    group: RecordHeadingGroup & { id: number; name: string };
    isOpen: boolean;
    bodyId: string;
    onToggle: () => void;
    children: ReactNode;
}) {
    return (
        <section aria-labelledby={`${bodyId}-name`}>
            <h3 className="border-b">
                <button
                    type="button"
                    aria-expanded={isOpen}
                    aria-controls={bodyId}
                    onClick={onToggle}
                    className={cn(
                        'flex w-full items-center gap-2 py-2 text-left outline-none',
                        'focus-visible:ring-2 focus-visible:ring-ring',
                    )}
                >
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform',
                            !isOpen && '-rotate-90',
                        )}
                    />
                    <span
                        id={`${bodyId}-name`}
                        className="text-sm font-semibold wrap-anywhere"
                    >
                        {group.name}
                    </span>
                    <span className="text-xs font-normal text-muted-foreground">
                        {group.fieldCodes.length} 項目
                    </span>
                </button>
            </h3>
            <div id={bodyId} hidden={!isOpen} className="pt-4">
                {children}
            </div>
        </section>
    );
}

/**
 * 見出しのまとまり(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 2・3 節。詳細とフォームで使う)。
 * 見出しの行 = 名前・「<n> 項目」・開閉の印(chevron)。押すと畳む / 開く(ボタンなので Enter・Space も使える。aria-expanded)。
 * 既定 = 全部開く。畳んだ状態は保たない(この部品の state だけ = 再読み込みで全部開く。URL にも映さない)。頭のまとまりは見出しが無く畳めない。
 */
export function RecordHeadingGroups({
    groups,
    children,
    idPrefix,
    reveal,
}: Props) {
    const [closed, setClosed] = useState<number[]>([]);
    const [seenRequest, setSeenRequest] = useState(reveal?.request ?? 0);

    if (reveal !== undefined && reveal.request !== seenRequest) {
        setSeenRequest(reveal.request);
        setClosed(
            withOpened(closed, headingIdsContaining(groups, reveal.codes)),
        );
    }

    return (
        <div className="flex flex-col gap-5">
            {groups.map((group) =>
                isHeadingGroup(group) ? (
                    <HeadingSection
                        key={group.id}
                        group={group}
                        isOpen={!closed.includes(group.id)}
                        bodyId={`${idPrefix}-heading-${group.id}`}
                        onToggle={() =>
                            setClosed(toggleClosed(closed, group.id))
                        }
                    >
                        {children(group)}
                    </HeadingSection>
                ) : (
                    <div key="head">{children(group)}</div>
                ),
            )}
        </div>
    );
}
