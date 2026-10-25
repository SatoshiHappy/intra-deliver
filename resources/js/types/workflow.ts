import type {
    RecordDetail,
    RecordFieldValue,
    RecordHeadingGroup,
    RecordModuleDefinition,
} from './records';
import type { RouteStepState } from './status';
import type { WorkflowApplyTarget } from './workflowApplyTarget';
import type {
    WorkflowApplyAttachment,
    WorkflowAttachmentLimits,
} from './workflowAttachments';
import type {
    WorkflowCaseRow,
    WorkflowCurrentSummary,
    WorkflowDeadline,
    WorkflowPerson,
    WorkflowPullBackNode,
} from './workflowRows';
import type {
    WorkflowAction,
    WorkflowCompletionReason,
    WorkflowConfirmationProgress,
    WorkflowNodeKind,
    WorkflowNodeState,
} from './workflowValues';

/** ワークフローの画面の型(設計/ワークフロー/画面/README.md 1.2・2 節・画面/申請と案件.md 3〜5 節。App\Queries\Workflow の形。日時は時差つき。人の名前が無ければコード) */

/** 申請の画面の明細の行(id が無い = 新しい行。一時保存の行はいつも id を持たない)。業務データの入れ物の RecordDetailRow(id が必ずある)は変えない */
export type WorkflowDetailRowInput = {
    id?: number;
    values: Record<string, RecordFieldValue>;
};

/** フローの一覧のページ Workflow/FlowIndex の行(申請できるフロー。並びの順) */
export type WorkflowApplicableFlow = {
    code: string;
    name: string;
    description: string | null;
    moduleName: string;
};

/** 申請・一時保存・再申請のページ Workflow/Apply の props(設計/ワークフロー/画面/申請と案件.md 3 節) */
export type WorkflowApplyProps = {
    mode: 'new' | 'draft' | 'reapply' | 'record'; // record = 既存のデータからの申請(一時保存を出さない)
    flow: { code: string; name: string; description: string | null };
    case: { id: number; number: string | null; revision: number } | null; // 新規は null
    module: RecordModuleDefinition; // 業務データの入れ物の定義(新規・一時保存 = create、再申請・既存のデータ = update の判定の後)
    headings: RecordHeadingGroup[]; // 見出しのまとまり(レコードの共通機能/詳細の画面/見出し.md の 3 節。フォームの欄を見出しで畳む)
    initial: {
        name: string | null;
        comment: string | null;
        values: Record<string, RecordFieldValue>; // 一時保存 = draftValues、再申請・既存のデータ = 今のレコード
        details: Record<string, WorkflowDetailRowInput[]>; // 一時保存 = id の無い行、再申請・既存のデータ = id のある行
    } | null; // 新規は null(既定値で始める)
    titleField: string | null; // 表示の項目のコード(案件名の既定の案内に使う)
    recordId: number | null; // 書くレコードの id(再申請 = 案件のレコード、既存のデータ = そのレコード、新規・一時保存 = null。参照の候補から除く)
    baseDate: string; // 申請基準日の初期値と next を引いた日(YYYY-MM-DD。再申請は案件の値)
    baseDateRange: { min: string | null; max: string | null }; // 申請基準日に選べる範囲(再申請は null)
    nameMaxLength: number; // 案件名の文字数の上限(永続化の層の WorkflowCase::NAME_MAX)
    commentMaxLength: number; // コメントの文字数の上限(永続化の層の WorkflowCaseHistory::COMMENT_MAX)
    next: {
        version: number | null; // 申請基準日の版の番号(無ければ null)
        noVersionMessage: string | null; // 版が無いときの文(申請基準日の日付入り。サーバーの業務処理の 422 と同じ文。版があれば null)
        departmentName: string | null; // 申請基準日のカレント組織 = 担当組織
        nodes: {
            name: string;
            targets: WorkflowPerson[];
            blockedReason: string | null; // 申請者承認防止で今の展開が申請者本人だけのときの文言(申請を押せない)
        }[]; // 申請の次に到達するステップの今の展開(位置の順)
        dependsOnContent: boolean; // 次が分岐開始で、内容で到達するステップが決まる
    };
    attachment: WorkflowApplyAttachment;
    recordVersion: string | null; // 既存のデータからの申請だけ: 開いたときのレコードの版(申請で record_version に送る)
    recordTitle: string | null; // 既存のデータからの申請だけ: 見出しの表示の項目の値(無ければ null)
} & WorkflowApplyTarget;

/** 再申請の差分(再申請の行だけ。設計/ワークフロー/画面/申請と案件.md 5 節) */
export type WorkflowDiff = {
    fields: {
        code: string;
        before: RecordFieldValue;
        after: RecordFieldValue;
    }[];
    details: {
        code: string;
        rows: {
            change: 'added' | 'removed' | 'changed';
            rowId: number;
            before: Record<string, RecordFieldValue> | null;
            after: Record<string, RecordFieldValue> | null;
            changedCodes: string[];
        }[];
    }[];
};

/** 図の 1 ノード(定義と案件で共通。ルートの深さ/画面.md 1.2)。並びはサーバーの写した並び(展開の行は親の直後に番号の順) */
export type WorkflowDiagramNode = {
    id: number;
    code: string | null; // 展開の行は null
    name: string;
    kind: WorkflowNodeKind;
    parentId: number | null; // 展開の行の親(案件だけ)
    sequence: number | null; // 展開の行の番号(案件だけ)
    subtitle: string | null; // 設定から作った短い文(「課長が指定」など)
    state: WorkflowNodeState | null; // 案件だけ(定義は null)
    people: (WorkflowPerson & { isMe: boolean })[]; // 案件: 現在のステップは処理対象者、処理済みは処理者
    placeholders: { label: string; optional: boolean }[]; // 定義の配置のノードだけ
    consensus: { text: string } | null; // 縦配置だけ(「完了の条件 承認 2 人 現在 1 人」)
};

/** 図の 1 辺。taken = 分岐開始から出る辺の到達の時の評価(false = 通らなかった枝)。ほかの辺と定義の図は null */
export type WorkflowDiagramEdge = {
    from: number;
    to: number;
    label: string | null;
    taken: boolean | null;
};

/** 状態の札・表の状態の語(認可と一覧.md 2 節の写しの表。詳細の図・表・合議の行は保留中を 'hold' にする = 案件の画面.md の 4 節) */
export type WorkflowStepState = {
    state: RouteStepState | 'hold';
    label: string;
};

/** 処理実行者の表示(実行者が処理者と違えば「{executor}({actor}さんの代理)」= 案件の運用/画面/README.md の 1.2 節) */
export type WorkflowActorView = {
    actor: WorkflowPerson;
    executor: WorkflowPerson | null; // 代理で処理したときだけ
};

/** 案件の詳細の図と表の 1 ノード(表のための処理担当者と、処理の種別・日時・処理実行者を持つ) */
export type WorkflowCaseDiagramNode = WorkflowDiagramNode & {
    stepState: WorkflowStepState;
    targets: (WorkflowPerson & { isMe: boolean })[]; // 現在のステップの処理担当者(ほかは空)
    processed:
        | ({ action: WorkflowAction; at: string | null } & WorkflowActorView)
        | null; // 処理済みだけ
    // 処理の時に指定するステップで、まだ指定が無い。枠の文 = docs の 設計/ワークフロー/ルートの深さ/画面.md の 8 節(文言の持ち主は WorkflowDiagram.tsx)
    awaitingDesignation: boolean;
};

/** 案件の詳細の合議の進み具合(縦配置が展開中か通過のとき。ルートの深さ/画面.md 7 節) */
export type WorkflowCouncil = {
    nodeId: number;
    name: string;
    approved: number; // 承認した人数
    required: number; // 必要な人数
    pending: number; // 未処理の人数
    passed: boolean; // 可決した(合議は完了)
    rows: {
        nodeId: number;
        name: string; // 表示名(画面には描かない)
        action: WorkflowAction | null; // 処理済みの行の処理の種別
        person: (WorkflowPerson & { isMe: boolean }) | null;
        department: WorkflowPerson | null; // 申請基準日の所属
        at: string | null; // 処理日時
        stepState: WorkflowStepState;
    }[];
};

/** 案件の詳細の履歴の行(古い順) */
export type WorkflowCaseHistory = WorkflowActorView & {
    id: number;
    nodeName: string;
    action: WorkflowAction;
    at: string;
    comment: string | null;
    sendBackNodeName: string | null; // 差戻しの行だけ(戻るの戻り先は moveTargetName)
    diff: WorkflowDiff | null; // 再申請の行だけ
    diffBaseAt: string | null; // 比べた直前の申請・再申請の日時
    assignments: { nodeName: string; people: WorkflowPerson[] }[]; // 承認の行の指定(指定されたステップごと。写した並び。people が空 = 0 人を指定 = 省略)
    users: WorkflowPerson[]; // 担当変更先・変更後の処理担当者(処理担当者の変更・再展開)・指定の変更の人(並びの順)
    moveTargetName: string | null; // ステップの移動(戻る)の戻り先
};

/** 処理対象者の候補(口 workflow.cases.assignee-candidates の users の要素) */
export type WorkflowAssigneeCandidate = WorkflowPerson & {
    mainDepartmentName: string | null;
};

/** 承認のダイアログの「指定するステップ」1 つ(ルートの深さ/画面.md 6 節) */
export type WorkflowDesignation = {
    nodeId: number;
    name: string;
    kind: 'approve' | 'serial' | 'parallel'; // 指定されるステップの種類(横配置は並べ替えのボタンを出す)
    kindLabel: string; // 承認・横配置・縦配置
    min: number;
    max: number;
    decision: {
        rule: 'all' | 'count' | 'ratio';
        value: number | null;
        required: number[]; // 人数 i + 1 のときの必要な数 k(添字 i。人数 1〜max。式はサーバーの WorkflowDecisionRule::required() の 1 か所)
    } | null; // 縦配置の可決の条件
    initial: WorkflowPerson[]; // 今の指定
};

/** ステップごとの処理の操作(承認・承認終了・否認・差戻しと、保留・保留解除・担当変更 = 案件の運用/画面/案件の画面.md の 4 節) */
export type WorkflowNodeOperation =
    | 'approve'
    | 'approveEarly'
    | 'reject'
    | 'sendBack'
    | 'hold'
    | 'release'
    | 'transfer';

/** 処理担当者の選択肢 1 つ(自分か代理元。並び = 自分が先、代理元はその後) */
export type WorkflowPrincipal = { id: number; name: string; isSelf: boolean };

/** 案件の詳細のステップごとの操作の可否(ルートの深さ/画面.md 7 節、案件の運用/画面/案件の画面.md の 4 節) */
type WorkflowNodeAction = {
    nodeId: number;
    name: string;
    approve: boolean;
    approveEarly: boolean;
    reject: boolean;
    sendBack: boolean;
    hold: boolean;
    release: boolean;
    transfer: boolean;
    blockedReason: string | null; // 申請者承認防止に当たるときの文言(承認・承認終了・否認を押せない)
    holdBlockedReason: string | null; // 申請者承認防止に当たるときの保留の文言(保留を押せない)
    sendBackTargets: { id: number; name: string }[]; // 写した並び
    sendBackRestricted: boolean; // 差戻しできる先の制限が効いている(制限の 1 行を出す)
    isCouncilVote: boolean; // 縦配置の子(否認のダイアログの文を変える)
    designations: WorkflowDesignation[];
    principals: Partial<Record<WorkflowNodeOperation, WorkflowPrincipal[]>>; // 操作ごとの処理担当者の選択肢(許される操作だけ。1 つなら欄を出さない)
};

/** 案件の詳細の案件に 1 つの操作の可否(状態 × 役割 × 入口の 403 の判定) */
type WorkflowCaseCan = {
    pullBack: boolean;
    reapply: boolean;
    withdraw: boolean;
    confirm: boolean; // 自分が確認対象者の開いている確認がある(確認の面の主ボタン)
};

/** 案件の詳細の引戻せるステップ 1 つ(principalUserId = h の処理者。自分なら null。選択肢でなく送る値) */
export type WorkflowCasePullBackNode = WorkflowPullBackNode & {
    principalUserId: number | null;
};

/** 案件の詳細の確認の面のステップ 1 つ(確認する人を持つステップだけ。写した並び) */
export type WorkflowCaseConfirmation = {
    nodeId: number;
    stepName: string;
    people: {
        person: WorkflowPerson;
        department: string | null; // 主所属の名前
        isMe: boolean;
        status: WorkflowConfirmationProgress;
        confirmedAt: string | null; // 最新の確認の日時
    }[];
};

/** 案件の詳細のページ Workflow/CaseShow の props(設計/ワークフロー/画面/申請と案件.md 5 節) */
export type WorkflowCaseShowProps = {
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
    content: {
        module: RecordModuleDefinition;
        record: RecordDetail;
        updatedAfterSubmission: boolean;
    };
    nodes: WorkflowCaseDiagramNode[];
    edges: WorkflowDiagramEdge[];
    councils: WorkflowCouncil[];
    histories: WorkflowCaseHistory[];
    related: { heading: string; cases: WorkflowCaseRow[] }[]; // つながる申請の群(並び・見出し = 業務データとの接続の 6 節。空の群は入れない)
    can: WorkflowCaseCan;
    nodeActions: WorkflowNodeAction[]; // 自分が処理対象者の現在のステップごと
    pullBackNodes: WorkflowCasePullBackNode[]; // 自分か代理元として引戻せるステップ(写した並び。未完了だけ)
    casePrincipal: number | null; // 再申請・取止めで送る処理者(自分が申請者なら null、ほかは申請者)
    proxyNote: string | null; // 代理の 1 行(自分が代理元としてだけ処理できるとき)
    confirmations: WorkflowCaseConfirmation[]; // 確認の面
    commentMaxLength: number; // コメントの文字数の上限(処理のダイアログ)
    canAttachOnWithdraw: boolean; // 取止めで添付を追加できる
    attachmentLimits: WorkflowAttachmentLimits | null; // どの入口も添付を受けなければ null
};
