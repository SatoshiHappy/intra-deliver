import type { WorkflowDiagramEdge, WorkflowDiagramNode } from './workflow';
import type {
    WorkflowDecisionRule,
    WorkflowNodeKind,
    WorkflowRelativeBase,
    WorkflowRelativeScope,
    WorkflowSubjectType,
} from './workflowValues';

/**
 * 定義の管理の画面の型(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 2 節・3 節。App\Queries\Workflow の
 * FlowDefinitionList・FlowDefinitionDetail・FlowStepSettings の形)。日付は YYYY-MM-DD。
 */

/** 版の期間(endDate = 表示の終了日 = 次の版の開始日の前日。最後の版は null) */
export type WorkflowFlowVersionPeriod = {
    number: number;
    startDate: string;
    endDate: string | null;
};

/** フロー定義の一覧の行(currentVersion = 今日の版。無ければ null。referrerSummary = 参照者の名前を「、」で。無ければ null) */
export type WorkflowFlowDefinitionRow = {
    code: string;
    name: string;
    moduleName: string;
    currentVersion: WorkflowFlowVersionPeriod | null;
    versionCount: number;
    referrerSummary: string | null;
};

/** 一覧のページ Workflow/Admin/FlowIndex の props(並び = フローの並び順) */
export type WorkflowAdminFlowIndexProps = {
    flows: WorkflowFlowDefinitionRow[];
};

/** 対象者・参照者の行(名前の無い相手は「#{id}」) */
export type WorkflowSubjectRow = {
    type: WorkflowSubjectType;
    id: number;
    name: string;
};

/** 版の行(並び = 番号の降順。endDate = 表の終了日(次の版の開始日)、displayEndDate = その前日。isCurrent = 今日の版) */
export type WorkflowFlowVersionRow = {
    number: number;
    startDate: string;
    endDate: string | null;
    displayEndDate: string | null;
    isDisabled: boolean;
    isCurrent: boolean;
};

/** ステップの処理対象者の指定(分岐・同期は null) */
export type WorkflowStepTarget =
    | { kind: 'fixed'; rows: WorkflowSubjectRow[] }
    | {
          kind: 'relative';
          base: WorkflowRelativeBase;
          scope: WorkflowRelativeScope;
          companyPostId: number | null;
      }
    | { kind: 'designated'; designatorCode: string; min: number; max: number };

/** 選択の欄のステップ(写した並び) */
export type WorkflowNodeChoice = { code: string; name: string };

/** 図で選んだステップの設定(持たない設定は null。summary = 設定から作った要約の 1 文) */
export type WorkflowStepSettings = {
    code: string;
    name: string;
    kind: WorkflowNodeKind;
    summary: string;
    target: WorkflowStepTarget | null;
    preventsApplicantApproval: boolean | null; // 承認・横配置・縦配置だけ
    sendBack: { candidates: WorkflowNodeChoice[]; selected: string[] } | null; // 承認・横配置・縦配置だけ
    decision: { rule: WorkflowDecisionRule; value: number | null } | null; // 縦配置だけ
};

/** 詳細のタブ */
export type WorkflowFlowDefinitionTab = 'diagram' | 'versions' | 'referrers';

/**
 * 詳細のページ Workflow/Admin/FlowShow の props(selectedVersion・tab は解釈した後の値。diagram・selectedNode・referrers・options は
 * 部分の再読み込み(only)で取り直せる)
 */
export type WorkflowAdminFlowShowProps = {
    flow: { code: string; name: string; moduleName: string };
    versions: WorkflowFlowVersionRow[];
    selectedVersion: number;
    tab: WorkflowFlowDefinitionTab;
    today: string; // サーバーの今日(Asia/Tokyo の YYYY-MM-DD。版を追加の開始日の範囲)
    diagram: { nodes: WorkflowDiagramNode[]; edges: WorkflowDiagramEdge[] };
    selectedNode: WorkflowStepSettings;
    referrers: { rows: WorkflowSubjectRow[] };
    options: {
        companyPosts: { id: number; name: string }[];
        designators: WorkflowNodeChoice[]; // 選んだステップの指定するステップの候補(承認・横配置・縦配置のほかは空)
    };
};

/** 対象者の候補の応答 GET workflow.admin.subject-candidates(主所属はユーザーだけ。ほかは null) */
export type WorkflowSubjectCandidatesResponse = {
    candidates: {
        id: number;
        code: string;
        name: string | null;
        mainDepartmentName: string | null;
    }[];
};
