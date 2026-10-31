import { DetailItem } from '@/components/DetailItem';
import { RecordValue } from '@/components/RecordValue';
import { restrictedText, VIEWERS_FIELD } from '@/lib/recordRowAccess';
import type { RowAccessInput } from '@/types';

/**
 * 詳細の区画「参照制限」(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.3): 管理者の見出しのまとまりの外に置き、チェックの
 * 表示と、制限するときは参照者(相手の種類の出し方は型「ユーザーと組織(複数)」の値と同じ値の部品。0 人は「—」)。名前と値は項目の
 * 格子と同じ部品・列で並べる(画面の意匠の方針 9(e))。
 */
export function RecordRowAccessSummary({
    rowAccess,
}: {
    rowAccess: RowAccessInput;
}) {
    return (
        <section aria-label="参照制限">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm @md:grid-cols-2 @3xl:grid-cols-3">
                <DetailItem name="参照制限">
                    {restrictedText(rowAccess.restricted)}
                </DetailItem>
                {rowAccess.restricted && (
                    <DetailItem name={VIEWERS_FIELD.name}>
                        <RecordValue
                            field={VIEWERS_FIELD}
                            value={rowAccess.viewers}
                        />
                    </DetailItem>
                )}
            </dl>
        </section>
    );
}
