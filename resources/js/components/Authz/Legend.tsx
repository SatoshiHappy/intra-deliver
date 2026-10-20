import type { ReactNode } from 'react';
import { NoSettingMark, SettingMark } from '@/components/Authz/SettingMark';
import { ResultWord } from '@/components/Authz/ResultWord';
import { Badge } from '@/components/ui/badge';
import type { GridTab } from '@/lib/Authz/gridView';

function LegendRow({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-0.5">
            {children}
        </div>
    );
}

function LegendItem({ children }: { children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
            {children}
        </span>
    );
}

/**
 * 凡例の箱(枠つきの小さな箱に 2 行)。選んでいるタブに要る分だけ出す。
 */
export function Legend({ tab }: { tab: GridTab }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5 rounded-md border px-3 py-2 text-xs leading-4 text-muted-foreground">
            {tab === 'settings' ? (
                <>
                    <LegendRow>
                        <LegendItem>
                            <SettingMark effect="permit" inherited={false} />
                            許可(直接)
                        </LegendItem>
                        <LegendItem>
                            <SettingMark effect="deny" inherited={false} />
                            禁止(直接)
                        </LegendItem>
                        <LegendItem>
                            <SettingMark effect="permit" inherited />
                            <SettingMark effect="deny" inherited />
                            継承(置き場の名前は、ポインタを合わせたときに表示)
                        </LegendItem>
                    </LegendRow>
                    <LegendRow>
                        <LegendItem>
                            <NoSettingMark />
                            設定なし
                        </LegendItem>
                        <LegendItem>
                            <Badge>該当</Badge>
                            判定する人に該当する対象者
                        </LegendItem>
                    </LegendRow>
                </>
            ) : (
                <>
                    <LegendRow>
                        <ResultWord result="permit" />
                        <ResultWord result="deny" />
                        <LegendItem>
                            <ResultWord result="block" />
                            閉塞中のリソース(行が赤地)
                        </LegendItem>
                    </LegendRow>
                    <LegendRow>
                        <LegendItem>
                            <span>—</span>
                            判定なし(この種類のリソースがない行)
                        </LegendItem>
                        <LegendItem>語の下の小さい字 = 効いた対象者</LegendItem>
                    </LegendRow>
                </>
            )}
        </div>
    );
}
