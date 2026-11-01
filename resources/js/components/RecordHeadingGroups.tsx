import { useState } from 'react';
import type { ReactNode } from 'react';
import { RecordFoldSection } from '@/components/RecordFoldSection';
import {
    headingIdsContaining,
    headingNameOf,
    toggleClosed,
    withOpened,
} from '@/lib/recordHeadingGroups';
import type { HeadingKey } from '@/lib/recordHeadingGroups';
import type { RecordHeadingGroup } from '@/types';

type Props = {
    /** 見出しのまとまり(サーバーの並びの順。頭のまとまりは id・name が null で、見出しの行は固定の「基本情報」) */
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
 * 見出しのまとまり(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 2・3 節。詳細とフォームで使う)。
 * 見出しの行 = 名前・開閉の印(chevron)。押すと畳む / 開く(ボタンなので Enter・Space も使える。aria-expanded)。
 * 既定 = 全部開く。畳んだ状態は保たない(この部品の state だけ = 再読み込みで全部開く。URL にも映さない)。
 * 頭のまとまりも固定の見出し「基本情報」で畳める(見出しの無い業務モジュールは全部の項目がその下に入る)。見出しの行は薄い帯。
 */
export function RecordHeadingGroups({
    groups,
    children,
    idPrefix,
    reveal,
}: Props) {
    const [closed, setClosed] = useState<HeadingKey[]>([]);
    const [seenRequest, setSeenRequest] = useState(reveal?.request ?? 0);

    if (reveal !== undefined && reveal.request !== seenRequest) {
        setSeenRequest(reveal.request);
        setClosed(
            withOpened(closed, headingIdsContaining(groups, reveal.codes)),
        );
    }

    return (
        <div className="flex flex-col gap-5">
            {groups.map((group) => (
                <RecordFoldSection
                    key={group.id ?? 'head'}
                    bodyId={`${idPrefix}-heading-${group.id ?? 'head'}`}
                    name={headingNameOf(group)}
                    isOpen={!closed.includes(group.id)}
                    onToggle={() => setClosed(toggleClosed(closed, group.id))}
                >
                    {children(group)}
                </RecordFoldSection>
            ))}
        </div>
    );
}
