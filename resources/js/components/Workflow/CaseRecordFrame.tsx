import { RecordShowLayout } from '@/components/RecordShowLayout';
import { RecordTabNotice } from '@/components/RecordTabNotice';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { CaseRecordTabs } from '@/components/Workflow/CaseRecordTabs';
import { CaseSidePanel } from '@/components/Workflow/CaseSidePanel';
import type { useRecordSidePanel } from '@/hooks/use-record-side-panel';
import type { ShownCaseRecordMode } from '@/lib/Workflow/caseRecordMode';
import { MASKED_NOTICE } from '@/lib/Workflow/caseShowTexts';
import type { WorkflowCaseShowProps } from '@/types';

type Panel = ReturnType<typeof useRecordSidePanel>;

/** 左のタブを入れるカード */
function MainCard({
    show,
    mode,
    onOpenAction,
}: {
    show: WorkflowCaseShowProps;
    mode: ShownCaseRecordMode;
    onOpenAction: OpenCaseAction;
}) {
    return (
        <section
            aria-label={`${show.case.name}の詳細`}
            className="rounded-xl border bg-card px-5 pt-1 pb-5 text-card-foreground"
        >
            <CaseRecordTabs {...show} mode={mode} onOpenAction={onOpenAction} />
        </section>
    );
}

/**
 * 申請の詳細の左右の枠(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2)。写しの形はサイドパネルの中身を
 * 読みに行かない(伏せた中身の帯を出さない)ので、レコードの詳細の枠を使わずここで組む。
 */
export function CaseRecordFrame({
    show,
    mode,
    panel,
    onOpenAction,
}: {
    show: WorkflowCaseShowProps;
    mode: ShownCaseRecordMode;
    panel: Panel;
    onOpenAction: OpenCaseAction;
}) {
    const main = (
        <MainCard show={show} mode={mode} onOpenAction={onOpenAction} />
    );

    if (mode.kind === 'live') {
        return (
            <RecordShowLayout
                open={panel.openKey !== null}
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

    return (
        <div className="grid grid-cols-[minmax(0,1fr)_clamp(320px,33.333%,420px)] items-start gap-4">
            <div className="@container min-w-0">{main}</div>
            <section
                aria-label="サイドパネル"
                className="rounded-xl border bg-card p-4 text-card-foreground"
            >
                <RecordTabNotice locked>{MASKED_NOTICE}</RecordTabNotice>
            </section>
        </div>
    );
}
