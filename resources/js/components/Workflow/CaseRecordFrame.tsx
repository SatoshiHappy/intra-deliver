import type { ReactNode } from 'react';
import { RecordShowLayout } from '@/components/RecordShowLayout';
import { CaseRecordTabs } from '@/components/Workflow/CaseRecordTabs';
import { CaseSidePanel } from '@/components/Workflow/CaseSidePanel';
import type { useRecordSidePanel } from '@/hooks/use-record-side-panel';
import type { WorkflowCaseShowProps } from '@/types';

type Panel = ReturnType<typeof useRecordSidePanel>;

/**
 * 左のタブを入れるカード。bar = 下端の中に置く帯。section を relative にするのは、帯の影を決める下端の印を置くため
 * (最後の面と帯の間の 24px はタブの下の余白が持つ = 画面の意匠の方針 14)。
 */
function MainCard({
    show,
    bar,
}: {
    show: WorkflowCaseShowProps;
    bar: ReactNode;
}) {
    return (
        <section
            aria-label={`${show.case.name}の詳細`}
            className="relative rounded-xl border bg-card px-5 pt-1 text-card-foreground"
        >
            <div className="pb-6">
                <CaseRecordTabs {...show} />
            </div>
            {bar}
        </section>
    );
}

/**
 * 申請の詳細の左右の枠(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4 節)。見出しは左の列の上、処理のボタンの帯は
 * 詳細のカードの下端の中(footer = 帯。見出しと帯は詳細のカードの幅。サイドパネルはカードの上端から = ユーザーの決定)。
 */
export function CaseRecordFrame({
    show,
    panel,
    heading,
    footer,
}: {
    show: WorkflowCaseShowProps;
    panel: Panel;
    heading: ReactNode;
    footer: ReactNode;
}) {
    return (
        <RecordShowLayout
            open={panel.openKey !== null}
            heading={heading}
            main={<MainCard show={show} bar={footer} />}
            side={
                <CaseSidePanel
                    panelProps={show}
                    route={{
                        module: show.content.module.code,
                        record: show.content.record.id,
                    }}
                    panel={panel}
                />
            }
        />
    );
}
