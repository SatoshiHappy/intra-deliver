import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 詳細の画面の左右の枠(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 1 節): 左 = 詳細、右 = サイドパネル
 * (開いた欄のパネルと帯。side は grid の子を 1 つか 2 つ返す)。パネルの幅 = 本文の 1/3 を 320〜420px に収める(Q25)。
 * 帯とパネルは枠の中に固定し、左の詳細だけが流れる。詳細の格子の列は左の面の幅で決める(@container。列の段階は詳細の中身が持つ)。
 */
export function RecordShowLayout({
    open,
    main,
    side,
}: {
    /** パネルを開いているか(列の数が変わる) */
    open: boolean;
    main: ReactNode;
    side: ReactNode;
}) {
    return (
        <div
            className={cn(
                'grid items-start gap-4',
                open
                    ? 'grid-cols-[minmax(0,1fr)_clamp(320px,33.333%,420px)_72px]'
                    : 'grid-cols-[minmax(0,1fr)_72px]',
            )}
        >
            <div className="@container min-w-0">{main}</div>
            {side}
        </div>
    );
}
