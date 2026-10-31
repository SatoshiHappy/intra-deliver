import type { WorkflowAttachmentLimits } from './workflowAttachments';
import type {
    WorkflowBulkOutcome,
    WorkflowCaseState,
    WorkflowCompletionReason,
} from './workflowValues';

/**
 * 案件一覧の行と props・一括の結果の型(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.2 節、画面/案件の画面.md の 2 節・3 節。
 * App\Queries\Workflow の形。日時は時差つき、日付は YYYY-MM-DD)。
 */

/** 人(名前が無ければ画面はコードを出す) */
export type WorkflowPerson = { id: number; code: string; name: string | null };

/** 処理担当者の選択肢 1 つ(自分か代理元。並び = 自分が先、代理元はその後) */
export type WorkflowPrincipal = {
    id: number;
    name: string;
    isSelf: boolean;
    canAttach: boolean; // この操作をこの人として行うときに添付を追加できる
};

/** 札の状態(再申請待ち > すべて保留中 > 承認待ち = 認可と一覧.md の 2.4 節) */
type WorkflowLabelState = 'waiting' | 'reapply' | 'hold';

/** 現在のステップの要約(札の状態と、現在のステップの文字列 = 同じ親の展開の行をまとめた名前。サーバーの 1 か所が作る) */
export type WorkflowCurrentSummary = {
    state: WorkflowLabelState;
    name: string;
};

/** 現在のノード 1 つ(保留中の札を名前の後に出す。同じ親の展開の行は親の名前でまとめる) */
export type WorkflowCurrentNode = {
    id: number;
    name: string; // 表示名(展開の行は「{親の名前} {子の名前}」)
    parentId: number | null; // 展開の行の親のノードの id(同じ親の子かを見る。展開の行でなければ null)
    parentName: string | null; // 展開の行の親の名前(展開の行でなければ null)
    childName: string | null; // 展開の行の子の名前(展開の行でなければ null)
    state: WorkflowLabelState;
};

/** 期限の表示(案件ごとの値 = 案件の操作/README.md の 1.4 節) */
export type WorkflowDeadline = {
    reachedOn: string | null;
    elapsedDays: number | null;
    dueOn: string | null;
    overdue: boolean;
};

/** 自分が引戻せるステップ 1 つ(blockedReason = 申請者承認防止で引戻せないときの文言。ダイアログが「決定」を押せなくする) */
export type WorkflowPullBackNode = {
    id: number;
    name: string;
    blockedReason: string | null;
    canAttach: boolean; // 引戻しで添付を追加できる(処理のダイアログが添付の欄を出す)
};

/** 一覧の行・関連する案件の行(タブが求めない項目は null・空・偽) */
export type WorkflowCaseRow = {
    id: number;
    number: string | null; // 'WF-000001'。一時保存は null
    name: string; // 一時保存は入力の案件名、無ければフロー名
    flowName: string;
    applicant: WorkflowPerson;
    appliedAt: string | null;
    currentSummary: WorkflowCurrentSummary | null; // 現在のステップが無ければ null
    currentNodes: WorkflowCurrentNode[];
    state: WorkflowCaseState;
    completionReason: WorkflowCompletionReason | null;
    revision: number;
    can: {
        pullBack: boolean;
        reapply: boolean;
        withdraw: boolean;
        editDraft: boolean;
        deleteDraft: boolean;
        bulkApprove: boolean;
        confirm: boolean;
    };
    pullBackNodes: WorkflowPullBackNode[]; // 自分が引戻せるステップ(写した並び。未完了だけ)
    canAttachOnWithdraw: boolean; // 取止めで添付を追加できる(関連する案件の行は偽)
    deadline: WorkflowDeadline | null; // 未処理の行だけ
    principals: WorkflowPerson[]; // 未処理の行の代理元
    confirmation: { stepNames: string[]; at: string | null } | null; // 未確認 = 確認が開いた日時、確認済 = 確認日時
    bulkApproval: {
        nodeId: number;
        nodeName: string;
        principal: WorkflowPerson | null; // 代理元として承認するときの代理元(本人なら null)
    } | null; // can.bulkApprove が真の未処理の行だけ
};

/** 案件一覧のタブと切替(読み取りが解釈した値) */
export type WorkflowCaseTab =
    | 'draft'
    | 'pending'
    | 'unconfirmed'
    | 'processed'
    | 'confirmed'
    | 'referenced';
export type WorkflowCaseScope = 'active' | 'completed';

/** 案件一覧のページ Workflow/CaseIndex の props */
export type WorkflowCaseIndexProps = {
    query: { tab: WorkflowCaseTab; scope: WorkflowCaseScope; q: string };
    counts: { draft: number; pending: number; unconfirmed: number };
    cases: {
        data: WorkflowCaseRow[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
    commentMaxLength: number; // コメントの文字数の上限(行の処理のダイアログと一括承認のダイアログ)
    attachmentLimits: WorkflowAttachmentLimits | null; // どの行の入口も添付を受けなければ null
};

/** 一括承認・一括確認の結果(フラッシュ bulkResult。全部が成功したときは来ない) */
export type WorkflowBulkResult = {
    action: 'approve' | 'confirm';
    total: number;
    succeeded: number;
    rows: {
        caseId: number;
        name: string | null; // 閲覧できない申請は null
        number: string | null;
        flowName: string | null;
        result: WorkflowBulkOutcome;
        guidance: string | null;
    }[];
};
