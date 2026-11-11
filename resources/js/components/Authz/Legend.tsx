import type { ReactNode } from 'react';
import { SettingMark } from '@/components/Authz/SettingMark';
import { ResultWord } from '@/components/Authz/ResultWord';
import { Badge } from '@/components/ui/badge';
import type { GridTab } from '@/lib/Authz/gridView';

/**
 * 凡例の 1 項目(印と説明。箱の格子の 2 列に入る)。
 */
function LegendItem({
    mark,
    children,
}: {
    mark?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <>
            <span className="inline-flex items-center gap-1">{mark}</span>
            <span>{children}</span>
        </>
    );
}

/**
 * 凡例の箱。印の列と説明の列を縦にそろえるため 1 項目 1 行にする(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.3 の「凡例」)。
 */
export function Legend({ tab }: { tab: GridTab }) {
    return (
        <div className="grid min-w-0 grid-cols-[auto_1fr] items-center gap-x-2 gap-y-0.5 rounded-md border px-3 py-2 text-xs leading-4 text-muted-foreground">
            {tab === 'settings' ? (
                <>
                    <LegendItem
                        mark={<SettingMark effect="permit" inherited={false} />}
                    >
                        許可(直接)
                    </LegendItem>
                    <LegendItem
                        mark={<SettingMark effect="deny" inherited={false} />}
                    >
                        禁止(直接)
                    </LegendItem>
                    <LegendItem
                        mark={
                            <>
                                <SettingMark effect="permit" inherited />
                                <SettingMark effect="deny" inherited />
                            </>
                        }
                    >
                        継承(元の行の名前は、ポインタを合わせたときに表示)
                    </LegendItem>
                    <LegendItem mark={<Badge>該当</Badge>}>
                        判定する人に該当する対象者
                    </LegendItem>
                </>
            ) : (
                <>
                    <LegendItem mark={<ResultWord result="permit" />} />
                    <LegendItem mark={<ResultWord result="deny" />} />
                    <LegendItem mark={<ResultWord result="block" />}>
                        使用停止中の認可対象(行が赤地)
                    </LegendItem>
                    <LegendItem>語の下の小さい字 = 効いた対象者</LegendItem>
                </>
            )}
        </div>
    );
}
