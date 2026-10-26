/**
 * 申請の画面の申請者の選択肢(設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節。
 * サーバーの App\Queries\Workflow が返す形。並び = 自分が先、代理元は昇順)。
 */
export type WorkflowApplicant = {
    id: number;
    name: string;
    department: string | null; // 主所属の名前(人の候補の入口と同じ読み方。無ければ null)
    isSelf: boolean;
};
