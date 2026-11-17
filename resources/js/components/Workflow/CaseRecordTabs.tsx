import { RecordRelatedDialog } from '@/components/RecordRelatedDialog';
import { RecordDetailTabs } from '@/components/RecordDetailTabs';
import { RecordTabNotice } from '@/components/RecordTabNotice';
import { CaseApplicationTab } from '@/components/Workflow/CaseApplicationTab';
import { CaseFlowTab } from '@/components/Workflow/CaseFlowTab';
import { useRecordRelated } from '@/hooks/use-record-related';
import {
    APPLICATION_TAB,
    FLOW_TAB,
    UPDATED_AFTER_SUBMISSION,
    VIEWERS_NOTE,
} from '@/lib/Workflow/caseShowTexts';
import { recordHeading } from '@/lib/recordValues';
import { show, store } from '@/routes/records/data';
import type { WorkflowCaseShowProps } from '@/types';

type Props = WorkflowCaseShowProps;

/** 詳細の後ろに順に差し込む申請タブ(中身は {@link CaseApplicationTab})とフロータブ({@link CaseFlowTab}) */
function caseTabs(props: Props) {
    return [
        {
            key: APPLICATION_TAB,
            label: '申請',
            body: <CaseApplicationTab {...props} />,
        },
        {
            key: FLOW_TAB,
            label: 'フロー',
            body: <CaseFlowTab {...props} />,
        },
    ];
}

/**
 * 申請の詳細の左のタブ(レコードの今の値で描く)と、関連レコードの「新規」のダイアログ(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の
 * 2 節。完了の前も後も同じ中身 = 4.2)。
 */
export function CaseRecordTabs(props: Props) {
    const { module, record } = props.content;
    const related = useRecordRelated(
        props.relatedRecords,
        props.relatedRecordRows,
    );

    return (
        <>
            <RecordDetailTabs
                module={module}
                headings={props.headings}
                record={record}
                related={{
                    ...related,
                    rowHref: (moduleCode, recordId) =>
                        show.url({ module: moduleCode, record: recordId }),
                }}
                extraTabs={caseTabs(props)}
                fieldsNotice={
                    props.content.updatedAfterSubmission && (
                        <RecordTabNotice tone="warning">
                            {UPDATED_AFTER_SUBMISSION}
                        </RecordTabNotice>
                    )
                }
                viewersNote={VIEWERS_NOTE}
            />
            <RecordRelatedDialog
                open={related.dialog.open}
                form={related.dialog.form}
                status={related.dialog.status}
                recordId={record.id}
                ownerText={`${module.name}「${recordHeading(module, record)}」`}
                storeAction={(moduleCode) => store(moduleCode)}
                onClose={related.dialog.close}
            />
        </>
    );
}
