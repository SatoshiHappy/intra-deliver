import { Head } from '@inertiajs/react';
import {
    CaseActionDialog,
    useCaseActionDialog,
} from '@/components/Workflow/CaseActionDialog';
import { CaseActionBar } from '@/components/Workflow/CaseActionBar';
import { CaseOperationDialog } from '@/components/Workflow/CaseOperationDialog';
import { CaseHeading } from '@/components/Workflow/CaseHeading';
import { CaseRecordFrame } from '@/components/Workflow/CaseRecordFrame';
import { useRecordSidePanel } from '@/hooks/use-record-side-panel';
import { useOperationDialog } from '@/hooks/Workflow/use-operation-dialog';
import { PersonLinksContext } from '@/hooks/Workflow/use-person-links';
import { showAttachOf } from '@/lib/Workflow/caseActionAttach';
import { index, show } from '@/routes/workflow/cases';
import type { WorkflowCaseShowProps } from '@/types';

type Props = WorkflowCaseShowProps;

/**
 * 申請の詳細(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md)。処理と申請操作のダイアログを描き分けの外に置くのは、
 * 誤りの後に can や operation が偽になっても閉じないため。名前の札の「…」のリンクは props の links をページ全体(ダイアログを含む)へ渡す。
 */
export default function CaseShow(props: Props) {
    const { openAction, dialogProps } = useCaseActionDialog();
    const { openOperation, dialogProps: operationDialogProps } =
        useOperationDialog();
    const panel = useRecordSidePanel(
        props.panel?.items ?? [],
        props.panelContent,
    );

    return (
        <PersonLinksContext value={props.links}>
            <Head title={props.case.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <CaseRecordFrame
                    show={props}
                    panel={panel}
                    heading={<CaseHeading {...props} />}
                    footer={
                        <CaseActionBar
                            {...props}
                            onOpen={openAction}
                            onOpenOperation={openOperation}
                        />
                    }
                />
            </div>
            <CaseActionDialog {...dialogProps} attach={showAttachOf(props)} />
            <CaseOperationDialog {...operationDialogProps} />
        </PersonLinksContext>
    );
}

CaseShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: '申請一覧', href: index() },
        { title: props.case.name, href: show(props.case.id) },
    ],
});
