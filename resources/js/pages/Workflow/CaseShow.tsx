import { Head } from '@inertiajs/react';
import { CaseActionBar } from '@/components/Workflow/CaseActionBar';
import {
    CaseActionDialog,
    useCaseActionDialog,
} from '@/components/Workflow/CaseActionDialog';
import { CaseBasicInfo } from '@/components/Workflow/CaseBasicInfo';
import { CaseConfirmations } from '@/components/Workflow/CaseConfirmations';
import { CaseContent } from '@/components/Workflow/CaseContent';
import { CaseFlowInfo } from '@/components/Workflow/CaseFlowInfo';
import { CaseHistoryList } from '@/components/Workflow/CaseHistoryList';
import { CaseOperationArea } from '@/components/Workflow/CaseOperationArea';
import { RelatedCases } from '@/components/Workflow/RelatedCases';
import { showAttachOf } from '@/lib/Workflow/caseActionAttach';
import { index, show } from '@/routes/workflow/cases';
import type { WorkflowCaseShowProps } from '@/types';

type Props = WorkflowCaseShowProps;

/**
 * 申請の詳細(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節): 見出し = 申請名、右に操作のボタン({@see CaseActionBar})、
 * 見出しの下に代理の 1 行(proxyNote)。面 = 基本情報・申請内容・フロー情報(図と表の切替・合議の進み具合)・確認・履歴・関連する申請。
 * 処理のダイアログは描き分けの外に 1 つ(誤りの後に can が偽になっても閉じない = CaseActionDialog)。最後に申請操作の面({@see CaseOperationArea}。
 * operation があるときだけ)。
 */
export default function CaseShow(props: Props) {
    const { openAction, dialogProps } = useCaseActionDialog();
    const { case: workflowCase, content } = props;

    return (
        <>
            <Head title={workflowCase.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <h1 className="text-2xl font-semibold wrap-anywhere">
                            {workflowCase.name}
                        </h1>
                        <CaseActionBar {...props} onOpen={openAction} />
                    </div>
                    {props.proxyNote !== null && (
                        <p className="text-sm text-muted-foreground">
                            {props.proxyNote}
                        </p>
                    )}
                </div>
                <CaseBasicInfo data={workflowCase} />
                <CaseContent content={content} />
                <CaseFlowInfo
                    nodes={props.nodes}
                    edges={props.edges}
                    councils={props.councils}
                />
                <CaseConfirmations
                    caseId={workflowCase.id}
                    confirmations={props.confirmations}
                    canConfirm={props.can.confirm}
                />
                <CaseHistoryList
                    histories={props.histories}
                    module={content.module}
                />
                <RelatedCases related={props.related} />
                <CaseOperationArea {...props} onOpenAction={openAction} />
            </div>
            <CaseActionDialog {...dialogProps} attach={showAttachOf(props)} />
        </>
    );
}

CaseShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: '申請一覧', href: index() },
        { title: props.case.name, href: show(props.case.id) },
    ],
});
