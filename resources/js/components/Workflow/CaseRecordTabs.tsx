import { RecordRelatedDialog } from '@/components/RecordRelatedDialog';
import { RecordDetailTabs } from '@/components/RecordDetailTabs';
import { RecordRevisionTabs } from '@/components/RecordRevisionTabs';
import { RecordTabNotice } from '@/components/RecordTabNotice';
import { CaseApplicationTab } from '@/components/Workflow/CaseApplicationTab';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { useRecordRelated } from '@/hooks/use-record-related';
import type {
    CaseRecordContent,
    CaseSubmittedRecord,
    ShownCaseRecordMode,
} from '@/lib/Workflow/caseRecordMode';
import {
    APPLICATION_TAB,
    MASKED_NOTICE,
    SUBMITTED_SNAPSHOT_NOTICE,
    UPDATED_AFTER_SUBMISSION,
    VIEWERS_NOTE,
} from '@/lib/Workflow/caseShowTexts';
import { recordHeading } from '@/lib/recordValues';
import { show, store } from '@/routes/records/data';
import type { WorkflowCaseShowProps } from '@/types';

type Props = WorkflowCaseShowProps & { onOpenAction: OpenCaseAction };

/** 詳細の後ろに差し込む申請タブ(中身は {@link CaseApplicationTab}) */
function applicationTab(props: Props) {
    return {
        key: APPLICATION_TAB,
        label: '申請',
        body: <CaseApplicationTab {...props} />,
    };
}

/**
 * レコードの今の値で描く左のタブと、関連レコードの「新規」のダイアログ(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 2 節)。
 */
function LiveTabs({
    props,
    content,
}: {
    props: Props;
    content: CaseRecordContent;
}) {
    const { module, record } = content;
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
                extraTab={applicationTab(props)}
                fieldsNotice={
                    content.updatedAfterSubmission && (
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

/**
 * 最後の申請・再申請の時点の値の写しで描く左のタブ(同 4.2。今の値を持たないので、今の値の形と別に組む)。
 */
function SnapshotTabs({
    props,
    submittedRecord,
}: {
    props: Props;
    submittedRecord: CaseSubmittedRecord;
}) {
    const { maskedRelatedTab } = props;

    return (
        <RecordRevisionTabs
            view={submittedRecord}
            fieldsNotice={
                <RecordTabNotice>{SUBMITTED_SNAPSHOT_NOTICE}</RecordTabNotice>
            }
            extraTab={applicationTab(props)}
            viewersNote={VIEWERS_NOTE}
            lastTab={
                maskedRelatedTab === null
                    ? undefined
                    : {
                          key: maskedRelatedTab.key,
                          label: maskedRelatedTab.name,
                          body: (
                              <RecordTabNotice locked>
                                  {MASKED_NOTICE}
                              </RecordTabNotice>
                          ),
                      }
            }
        />
    );
}

/**
 * 申請の詳細の左のタブ(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 2 節)。今の値で描くか写しで描くかは
 * 呼び手が決めた mode(その形に要る値を持つ)に従う。
 */
export function CaseRecordTabs({
    mode,
    ...props
}: Props & { mode: ShownCaseRecordMode }) {
    return mode.kind === 'live' ? (
        <LiveTabs props={props} content={mode.content} />
    ) : (
        <SnapshotTabs props={props} submittedRecord={mode.submittedRecord} />
    );
}
