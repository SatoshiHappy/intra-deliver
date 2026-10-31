import { HeadingHelp } from '@/components/HeadingHelp';
import { Button } from '@/components/ui/button';
import { SubjectPartyField } from '@/components/Workflow/SubjectPartyField';
import {
    REFERRERS_ERROR_KEY,
    useReferrersForm,
} from '@/hooks/Workflow/use-referrers-form';
import { rowsSignature } from '@/lib/Workflow/subjectRows';
import type { WorkflowSubjectRow } from '@/types';

/** 面「参照者」の見出しの説明(見出しの横の「?」= 設計/画面の意匠/README.md の方針 9(d)) */
const REFERRERS_HELP =
    '参照者は、申請の閲覧のみ可能です。変更は、次に申請を表示したときから反映されます。';

/**
 * 面「参照者」(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.4 節): 見出しと横の「?」、参照者の欄
 * (札と、1 つの候補の一覧から 4 種類を選ぶ検索の欄。幅は会議の関係者の欄と同じく 2 列のフォームの 1 列分 = max-w-120)、
 * 「キャンセル」「保存」(面ごとの保存なので、ボタンの列は欄の並びの右端に揃える = 設計/画面の意匠/README.md の方針 11)。
 * 保存は面ごとで、参照者のすべての行を 1 回で送る(0 件 = 参照者なし)。
 */
function ReferrersSurface({
    flowCode,
    rows,
}: {
    flowCode: string;
    rows: WorkflowSubjectRow[];
}) {
    const form = useReferrersForm(flowCode, rows);

    return (
        <form
            noValidate
            onSubmit={form.submit}
            aria-labelledby="referrers-heading"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex items-center gap-2">
                <h2
                    id="referrers-heading"
                    className="text-[15px] font-semibold"
                >
                    参照者
                </h2>
                <HeadingHelp label="参照者">{REFERRERS_HELP}</HeadingHelp>
            </div>
            <div className="w-full max-w-120">
                <SubjectPartyField
                    id="referrers"
                    label="参照者"
                    rows={form.rows}
                    errors={form.errors}
                    errorKey={REFERRERS_ERROR_KEY}
                    onChange={form.change}
                />
            </div>
            <div className="flex w-full max-w-120 justify-end gap-2">
                <Button type="button" variant="outline" onClick={form.cancel}>
                    キャンセル
                </Button>
                <Button type="submit" disabled={form.isProcessing}>
                    保存
                </Button>
            </div>
        </form>
    );
}

/**
 * タブ「設定」(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.4 節): フローの設定の面を縦に並べる(今は面「参照者」。
 * 面「添付ファイル」は申請の周辺の画面で足す)。サーバーの行が替わったら(保存の後の取り直し)入力を作り直して、サーバーの値から始める。
 */
export function FlowSettingsTab({
    flowCode,
    referrers,
}: {
    flowCode: string;
    referrers: WorkflowSubjectRow[];
}) {
    return (
        <div className="flex flex-col gap-6">
            <ReferrersSurface
                key={rowsSignature(referrers)}
                flowCode={flowCode}
                rows={referrers}
            />
        </div>
    );
}
