import { Head, router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import {
    CaseActionDialog,
    useCaseActionDialog,
} from '@/components/Workflow/CaseActionDialog';
import { CaseActionBar } from '@/components/Workflow/CaseActionBar';
import { CaseHeading } from '@/components/Workflow/CaseHeading';
import { CaseRecordFrame } from '@/components/Workflow/CaseRecordFrame';
import { useRecordSidePanel } from '@/hooks/use-record-side-panel';
import { PersonLinksContext } from '@/hooks/Workflow/use-person-links';
import { showAttachOf } from '@/lib/Workflow/caseActionAttach';
import { caseRecordModeOf, reloadStepOf } from '@/lib/Workflow/caseRecordMode';
import { index, show } from '@/routes/workflow/cases';
import type { WorkflowCaseShowProps } from '@/types';

type Props = WorkflowCaseShowProps;

/**
 * 判定と中身がそろわない形({@link caseRecordModeOf} の reload)になったら全体を読み直す。歩の決め方は {@link reloadStepOf}。
 * 正本 = 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 6 節。
 */
function useReloadWhenStale(stale: boolean) {
    const reloaded = useRef(false);

    useEffect(() => {
        const step = reloadStepOf(stale, reloaded.current);

        reloaded.current = step.reloaded;
        if (step.reload) {
            router.reload();
        }
    }, [stale]);
}

/**
 * 申請の詳細(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md)。見出しの下の左のタブとサイドパネルは
 * {@link CaseRecordFrame}。処理のダイアログは描き分けの外に 1 つ(誤りの後に can が偽になっても閉じない = CaseActionDialog)。
 * 名前の札の「…」のリンクは props の links をページ全体(ダイアログを含む)へ渡す。
 */
export default function CaseShow(props: Props) {
    const { openAction, dialogProps } = useCaseActionDialog();
    const mode = caseRecordModeOf(props);
    const panel = useRecordSidePanel(
        mode.kind === 'live' ? (props.panel?.items ?? []) : [],
        props.panelContent,
    );
    useReloadWhenStale(mode.kind === 'reload');

    return (
        <PersonLinksContext value={props.links}>
            <Head title={props.case.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {mode.kind === 'reload' ? (
                    <CaseHeading {...props} />
                ) : (
                    <CaseRecordFrame
                        show={props}
                        mode={mode}
                        panel={panel}
                        onOpenAction={openAction}
                        heading={<CaseHeading {...props} />}
                        footer={
                            <CaseActionBar {...props} onOpen={openAction} />
                        }
                    />
                )}
            </div>
            <CaseActionDialog {...dialogProps} attach={showAttachOf(props)} />
        </PersonLinksContext>
    );
}

CaseShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: '申請一覧', href: index() },
        { title: props.case.name, href: show(props.case.id) },
    ],
});
