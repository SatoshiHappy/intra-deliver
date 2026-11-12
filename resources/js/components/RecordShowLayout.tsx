import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 詳細の画面の左右の枠(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 1 節): 左 = 詳細、右 = サイドパネル
 * (開いた欄のパネルと帯。side は grid の子を 1 つか 2 つ返す)。パネルの幅 = 本文の 1/3 を 320〜420px に収める(Q25)。
 * 帯とパネルは枠の中に固定し、左の詳細だけが流れる。詳細の格子の列は左の面の幅で決める(@container。列の段階は詳細の中身が持つ)。
 * heading を渡すと左の列の上に置き、右端を詳細の右端にそろえ、サイドパネルは詳細の上端から始める(申請の詳細 = ユーザーの決定)。
 */
export function RecordShowLayout({
    open,
    main,
    side,
    heading,
}: {
    /** パネルを開いているか(列の数が変わる) */
    open: boolean;
    main: ReactNode;
    side: ReactNode;
    heading?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'grid items-start gap-4',
                heading !== undefined && 'gap-y-6',
                open
                    ? 'grid-cols-[minmax(0,1fr)_clamp(320px,33.333%,420px)_72px]'
                    : 'grid-cols-[minmax(0,1fr)_72px]',
            )}
        >
            {heading !== undefined && (
                <div className="col-start-1 row-start-1 min-w-0">{heading}</div>
            )}
            <div
                className={cn(
                    '@container min-w-0',
                    heading !== undefined && 'col-start-1 row-start-2',
                )}
            >
                {main}
            </div>
            {heading === undefined ? (
                side
            ) : (
                <div className="contents [&>*]:row-start-2">{side}</div>
            )}
        </div>
    );
}
