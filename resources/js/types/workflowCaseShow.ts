/**
 * 申請の詳細のページ Workflow/CaseShow の props(docs の 設計/ワークフロー/画面/申請と案件.md 5 節、
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 6 節。サーバーの App\Queries\Workflow\CaseShowProps)。
 * props は層で分かれ、recordAccess が偽ならレコードの層は偽のときの値(content・panel・panelContent・relatedRecords・relatedRecordRows・
 * relatedRecordForm = null、sections・headings = 空)、写しの層(submittedRecord・maskedRelatedTab)は中身。真なら写しの層は null。
 */
import type { RecordRelatedProps } from './recordRelated';
import type {
    RecordHeadingGroup,
    RecordModuleDefinition,
    RecordShowDetail,
} from './records';
import type { RecordRevisionView, RecordShowPanelProps } from './recordShow';
import type {
    WorkflowCaseConfirmation,
    WorkflowCaseDiagramNode,
    WorkflowCaseHistory,
    WorkflowCasePullBackNode,
    WorkflowCouncil,
    WorkflowDiagramEdge,
    WorkflowNodeAction,
} from './workflow';
import type { WorkflowAttachmentLimits } from './workflowAttachments';
import type {
    WorkflowCaseCan,
    WorkflowCaseOperationPanel,
} from './workflowOperations';
import type {
    WorkflowCaseRow,
    WorkflowCurrentSummary,
    WorkflowDeadline,
    WorkflowPerson,
    WorkflowPersonLinks,
} from './workflowRows';
import type { WorkflowCompletionReason } from './workflowValues';

/** 申請の層(申請の閲覧の判定のまま) */
type WorkflowCaseShowCaseLayer = {
    case: {
        id: number;
        number: string;
        name: string;
        state: 'active' | 'completed';
        completionReason: WorkflowCompletionReason | null;
        flow: { code: string; name: string };
        applicant: WorkflowPerson;
        department: WorkflowPerson | null;
        appliedAt: string;
        completedAt: string | null;
        baseDate: string; // 申請基準日(YYYY-MM-DD)
        version: number; // 版の番号
        currentSummary: WorkflowCurrentSummary | null;
        deadline: WorkflowDeadline | null; // 未完了だけ(対象 = 現在のステップの全部)
        revision: number;
    };
    nodes: WorkflowCaseDiagramNode[];
    edges: WorkflowDiagramEdge[];
    councils: WorkflowCouncil[];
    histories: WorkflowCaseHistory[]; // 処理履歴(値の差分は持たない = サイドパネルの更新履歴)
    related: { heading: string; cases: WorkflowCaseRow[] }[]; // つながる申請の群(並び・見出し = 業務データとの接続の 6 節。空の群は入れない)
    can: WorkflowCaseCan;
    operation: WorkflowCaseOperationPanel | null; // 申請操作の面(can.operate が偽なら null)
    nodeActions: WorkflowNodeAction[]; // 自分が処理対象者の現在のステップごと
    pullBackNodes: WorkflowCasePullBackNode[]; // 自分か代理元として引戻せるステップ(写した並び。未完了だけ)
    casePrincipal: number | null; // 再申請・取止めで送る処理者(自分が申請者なら null、ほかは申請者)
    proxyNote: string | null; // 代理の 1 行(自分が代理元としてだけ処理できるとき)
    confirmations: WorkflowCaseConfirmation[]; // 確認の面
    commentMaxLength: number; // コメントの文字数の上限(処理のダイアログ)
    canAttachOnWithdraw: boolean; // 取止めで添付を追加できる
    attachmentLimits: WorkflowAttachmentLimits | null; // どの入口も添付を受けなければ null
    links: WorkflowPersonLinks; // 名前の札の「…」のリンク
};

/** レコードの層(サイドパネルと関連レコードはレコードの詳細と同じ型) */
type WorkflowCaseShowRecordLayer = RecordShowPanelProps &
    RecordRelatedProps & {
        content: {
            module: RecordModuleDefinition;
            record: RecordShowDetail; // レコードの詳細の record と同じ形(参照制限の今の値 rowAccess を含む)
            updatedAfterSubmission: boolean; // 最後の申請・再申請の後に版がある(詳細タブの先頭の 1 行)
        } | null;
        headings: RecordHeadingGroup[];
    };

/** 判定と写しの層 */
type WorkflowCaseShowRecordAccess = {
    recordAccess: boolean; // 申請書のレコードを閲覧できるか(いつも返る。部分の再読み込みで偽なら全体を読み直す)
    submittedRecord: RecordRevisionView | null; // recordAccess が偽のときだけ: 最後の申請・再申請の版の写し
    maskedRelatedTab: { key: string; name: string } | null; // recordAccess が偽のときだけ: 見える入口があるときの関連レコードのタブ(件数なし)
};

export type WorkflowCaseShowProps = WorkflowCaseShowCaseLayer &
    WorkflowCaseShowRecordLayer &
    WorkflowCaseShowRecordAccess;
