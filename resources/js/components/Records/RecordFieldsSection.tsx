import { Link } from '@inertiajs/react';
import { RecordFieldTable } from '@/components/Records/RecordFieldTable';
import { RecordHeadingDialog } from '@/components/Records/RecordHeadingDialog';
import { Button } from '@/components/ui/button';
import { create as createField } from '@/routes/records/admin/fields';
import type {
    RecordAdminHeading,
    RecordAdminItem,
    RecordAdminModule,
} from '@/types';

type Props = {
    module: RecordAdminModule;
    /** 見出し(名前。並びは items の中の位置で決まる。明細のモジュールは空) */
    headings: RecordAdminHeading[];
    /** 見出しと項目の 1 本の並び(項目の表の行の順。明細のモジュールは項目だけ) */
    items: RecordAdminItem[];
    /** 項目のコード → 値を持つレコード(明細は明細の行)の数 */
    fieldUsage: Record<string, number>;
    /** 見出しの名前の文字数の上限 */
    nameMaxLength: number;
};

/**
 * 業務モジュールの定義の画面の面「項目」(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3、レコードの共通機能/詳細の画面/見出し.md の 5 節):
 * 見出し「項目」の右に「見出しを追加」(明細のモジュールには出さない)と「項目を追加」、下に見出しと項目の表。
 */
export function RecordFieldsSection({
    module,
    headings,
    items,
    fieldUsage,
    nameMaxLength,
}: Props) {
    return (
        <section
            aria-labelledby="module-fields"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex items-center justify-between gap-4">
                <h2 id="module-fields" className="text-[15px] font-semibold">
                    項目
                </h2>
                <div className="flex items-center gap-2">
                    {module.parent === null && (
                        <RecordHeadingDialog
                            moduleCode={module.code}
                            heading={null}
                            nameMaxLength={nameMaxLength}
                            trigger={
                                <Button variant="outline" size="sm">
                                    見出しを追加
                                </Button>
                            }
                        />
                    )}
                    <Button asChild size="sm">
                        <Link href={createField(module.code)}>項目を追加</Link>
                    </Button>
                </div>
            </div>
            {items.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    項目はありません
                </p>
            ) : (
                <RecordFieldTable
                    module={module}
                    headings={headings}
                    items={items}
                    nameMaxLength={nameMaxLength}
                    fieldUsage={fieldUsage}
                />
            )}
        </section>
    );
}
