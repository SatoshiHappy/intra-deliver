import type { ReactNode } from 'react';

/**
 * 申請タブ・フロータブの面の並び。面の枠はタブの枠(カード)が持つので、面自身は枠を持たず、面の間の区切りの線と上下の余白はこの親が持つ
 * (設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 3 節。モック 4g)。
 */
export function CaseTabFaces({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-col divide-y *:py-6 *:first:pt-0 *:last:pb-0">
            {children}
        </div>
    );
}
