import type { ReactNode } from 'react';
import { RecordShowLayout } from '@/components/RecordShowLayout';
import { RecordTabNotice } from '@/components/RecordTabNotice';
import { CaseRecordTabs } from '@/components/Workflow/CaseRecordTabs';
import { CaseSidePanel } from '@/components/Workflow/CaseSidePanel';
import type { useRecordSidePanel } from '@/hooks/use-record-side-panel';
import type { ShownCaseRecordMode } from '@/lib/Workflow/caseRecordMode';
import { MASKED_NOTICE } from '@/lib/Workflow/caseShowTexts';
import type { WorkflowCaseShowProps } from '@/types';

type Panel = ReturnType<typeof useRecordSidePanel>;

/**
 * 左のタブを入れるカード。bar = 下端の中に置く帯。section を relative にするのは、帯の影を決める下端の印を置くため
 * (最後の面と帯の間の 24px はタブの下の余白が持つ = 画面の意匠の方針 14)。
 */
function MainCard({
    show,
    mode,
    bar,
}: {
    show: WorkflowCaseShowProps;
    mode: ShownCaseRecordMode;
    bar: ReactNode;
}) {
    return (
        <section
            aria-label={`${show.case.name}の詳細`}
            className="relative rounded-xl border bg-card px-5 pt-1 text-card-foreground"
        >
            <div className="pb-6">
                <CaseRecordTabs {...show} mode={mode} />
            </div>
            {bar}
        </section>
    );
}

/** 写しの形の枠(サイドパネルの位置に、中身を伏せた 1 行だけを出す。見出しと詳細は live の枠と同じ並び) */
function MaskedFrame({
    heading,
    main,
}: {
    heading: ReactNode;
    main: ReactNode;
}) {
    return (
        <div className="grid grid-cols-[minmax(0,1fr)_clamp(320px,33.333%,420px)] items-start gap-x-4 gap-y-6">
            <div className="min-w-0">{heading}</div>
            <div className="@container col-start-1 row-start-2 min-w-0">
                {main}
            </div>
            <section
                aria-label="サイドパネル"
                className="row-start-2 rounded-xl border bg-card p-4 text-card-foreground"
            >
                <RecordTabNotice locked>{MASKED_NOTICE}</RecordTabNotice>
            </section>
        </div>
    );
}

/**
 * 申請の詳細の左右の枠(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2)。写しの形はサイドパネルの中身を
 * 読みに行かない(伏せた中身の帯を出さない)ので、レコードの詳細の枠を使わずここで組む。見出しは左の列の上、処理のボタンの帯は
 * 詳細のカードの下端の中(footer = 帯。見出しと帯は詳細のカードの幅。サイドパネルはカードの上端から = ユーザーの決定)。
 */
export function CaseRecordFrame({
    show,
    mode,
    panel,
    heading,
    footer,
}: {
    show: WorkflowCaseShowProps;
    mode: ShownCaseRecordMode;
    panel: Panel;
    heading: ReactNode;
    footer: ReactNode;
}) {
    const main = <MainCard show={show} mode={mode} bar={footer} />;

    if (mode.kind === 'live') {
        return (
            <RecordShowLayout
                open={panel.openKey !== null}
                heading={heading}
                main={main}
                side={
                    <CaseSidePanel
                        panelProps={show}
                        route={{
                            module: mode.content.module.code,
                            record: mode.content.record.id,
                        }}
                        panel={panel}
                    />
                }
            />
        );
    }

    return <MaskedFrame heading={heading} main={main} />;
}
