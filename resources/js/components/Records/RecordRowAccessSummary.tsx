import { RecordValue } from '@/components/RecordValue';
import { restrictedText, VIEWERS_FIELD } from '@/lib/recordRowAccess';
import type { RowAccessInput } from '@/types';

/**
 * 詳細の区画「参照制限」(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.3): 管理者の見出しのまとまりの外に置き、チェックの
 * 表示と、制限するときは参照者(相手の種類の出し方は型「ユーザーと組織(複数)」の値と同じ値の部品。0 人は「—」)。
 */
export function RecordRowAccessSummary({
    rowAccess,
}: {
    rowAccess: RowAccessInput;
}) {
    return (
        <section
            aria-labelledby="record-show-row-access"
            className="flex flex-col gap-2 text-sm"
        >
            <h2
                id="record-show-row-access"
                className="text-xs text-muted-foreground"
            >
                参照制限
            </h2>
            <p>{restrictedText(rowAccess.restricted)}</p>
            {rowAccess.restricted && (
                <dl className="flex min-w-0 flex-col gap-1">
                    <dt className="text-xs text-muted-foreground">
                        {VIEWERS_FIELD.name}
                    </dt>
                    <dd className="min-w-0">
                        <RecordValue
                            field={VIEWERS_FIELD}
                            value={rowAccess.viewers}
                        />
                    </dd>
                </dl>
            )}
        </section>
    );
}
