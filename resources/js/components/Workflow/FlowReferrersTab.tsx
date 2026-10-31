import { Button } from '@/components/ui/button';
import { SubjectRowsField } from '@/components/Workflow/SubjectRowsField';
import {
    REFERRERS_ERROR_KEY,
    useReferrersForm,
} from '@/hooks/Workflow/use-referrers-form';
import { rowsSignature, withAddedRow } from '@/lib/Workflow/subjectRows';
import type { WorkflowSubjectRow } from '@/types';

/**
 * 見出し「参照者の指定」と、右に「行を追加」。
 */
function ReferrersHeading({ onAdd }: { onAdd: () => void }) {
    return (
        <div className="flex items-center justify-between gap-4">
            <h2 id="referrers-heading" className="text-[15px] font-semibold">
                参照者の指定
            </h2>
            <Button type="button" variant="outline" onClick={onAdd}>
                行を追加
            </Button>
        </div>
    );
}

/**
 * 参照者のタブの入力(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.4 節): 見出しと「行を追加」、
 * 説明の 1 行、対象者の行の表、「保存」「取消」。保存は参照者のすべての行を 1 回で送る。
 */
function ReferrersForm({
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
            <ReferrersHeading
                onAdd={() => form.change(withAddedRow(form.rows))}
            />
            <p className="text-sm text-muted-foreground">
                参照者は、申請の閲覧のみ可能です。変更は、次に申請を表示したときから反映されます。
            </p>
            <SubjectRowsField
                idPrefix="referrer"
                label="参照者"
                rows={form.rows}
                errors={form.errors}
                errorKey={REFERRERS_ERROR_KEY}
                onChange={form.change}
            />
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={form.cancel}>
                    取消
                </Button>
                <Button type="submit" disabled={form.isProcessing}>
                    保存
                </Button>
            </div>
        </form>
    );
}

/**
 * 参照者のタブ(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.4 節)。サーバーの行が替わったら(保存の後の取り直し)
 * 入力を作り直して、サーバーの値から始める。
 */
export function FlowReferrersTab(props: {
    flowCode: string;
    rows: WorkflowSubjectRow[];
}) {
    return <ReferrersForm key={rowsSignature(props.rows)} {...props} />;
}
