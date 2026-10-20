/**
 * 認可設定のページ Authz/Overview の props(サーバーの App\Queries\Authz\OverviewQuery・JudgeQuery が返す形。
 * docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.2)。表示名が無いときは null で、画面はコード(対象者は #<id>)を出す。
 */

/** 列 = ポリシーに使える対象者(2.1) */
export type AuthzGridSubject = {
    id: number; // 対象者の ID
    name: string | null; // 表示名(ロケールの行が無ければ null。画面は `#<id>`)
    category: { code: string; name: string | null }; // カテゴリ(行が無ければ name は null。画面はコード)
    matches: boolean; // 判定する人(1.4)が d にこの対象者に該当する
    builtin: boolean; // 組み込みの 3 列(削除できない。認可設定の編集.md 5.2)
    policyCount: number | null; // 列を削除する確かめの件数(すべての種類のポリシー。can.deleteSubject が偽なら null)
    fingerprint: string | null; // 同じ範囲の指紋(can.deleteSubject が偽なら null)
};

/** セル = 行 × 対象者 × アクションの効いている設定(2.2)。設定の無いセルは入れない */
export type AuthzGridSetting = {
    subjectGroupId: number;
    action: string; // アクション ID
    effect: 'permit' | 'deny';
    inheritedFrom: { code: string; name: string | null } | null; // 置き場(親から継承したとき)。行に直接置いたものは null
};

/** 判定 = 行 × アクション(2.2) */
export type AuthzGridJudgement = {
    action: string;
    result: 'permit' | 'deny' | 'block'; // 判定の結果
    decidingSubjectGroupIds: number[]; // 効いた対象者(効いたポリシーの対象者。列の並び)
};

/** 行 = リソースグループ(木を深さ優先に並べたもの。2.1) */
export type AuthzGridRow = {
    resourceGroupId: number;
    parentId: number | null; // 木の親の行(先頭 = null)
    depth: number; // 先頭 = 0
    code: string; // リソースグループ ID
    name: string | null; // 表示名(無ければ null。画面はコードを出す)
    settings: AuthzGridSetting[];
    judgements: AuthzGridJudgement[] | null; // null = この種類のリソースを持たない行(判定しない)。あればアクションの並び
};

/** 1 つのリソースの種類の格子 */
export type AuthzGrid = {
    resourceType: string; // 選んだリソースタイプの ID
    actions: string[]; // そのタイプのアクション(ResourceTypeCatalog の並び)
    subjects: AuthzGridSubject[]; // 列の並び(2.1)
    rows: AuthzGridRow[];
};

/** 判定する人の候補・判定した人(名称は基準日・ロケールの値。行が無ければ null) */
export type AuthzJudgeUser = { id: number; code: string; name: string | null };

/** 判定した人(null = ゲスト)と、その主所属の組織(無ければ null) */
export type AuthzJudge = {
    user: AuthzJudgeUser | null;
    department: { id: number; code: string; name: string | null } | null;
};

/**
 * 編集の操作ごとに、操作している人が行えるか(props can。docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.3)。
 */
export type AuthzCan = {
    editCells: boolean; // セルの押下
    addSubject: boolean; // 「列を追加」
    deleteSubject: boolean; // 「列を削除」
};

/** 「列を追加」の対象者の種類(認可設定の編集.md 3.1) */
export type AuthzSubjectKind = 'user' | 'role' | 'department' | 'post';

/** 「列を追加」の候補(authz.editor.subject-candidates の 1 件。主所属の名前はユーザーだけ) */
export type AuthzSubjectCandidate = {
    id: number;
    code: string;
    name: string | null;
    mainDepartmentName: string | null;
};

/** 候補の口 authz.editor.subject-candidates の応答 */
export type AuthzSubjectCandidates = {
    candidates: AuthzSubjectCandidate[];
};
