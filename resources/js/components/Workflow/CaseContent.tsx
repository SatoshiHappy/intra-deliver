import {
    RecordColumnHeads,
    RecordFieldName,
} from '@/components/RecordFieldBadges';
import { RecordValue } from '@/components/RecordValue';
import {
    Table,
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { isWideField, valueFields } from '@/lib/recordForm';
import { cn } from '@/lib/utils';
import type { WorkflowCaseShowProps } from '@/types';

/**
 * 申請内容(docs の 設計/ワークフロー/画面/申請と案件.md 5 節): 「業務モジュール：<名前>」の 1 行と、業務データの入れ物の詳細と同じ描き方(項目の並びの dl を 2 列の格子に、
 * 明細は項目ごとの見出しと表。値は RecordValue)。updatedAfterSubmission が真なら面の上に注意を出す。
 */
export function CaseContent({
    content,
}: {
    content: WorkflowCaseShowProps['content'];
}) {
    const { module, record } = content;
    const plainFields = valueFields(module);
    const detailsFields = module.fields.filter(
        (field) => field.type === 'details',
    );

    return (
        <section
            aria-labelledby="case-content-heading"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <h2 id="case-content-heading" className="text-[15px] font-semibold">
                申請内容
            </h2>
            {content.updatedAfterSubmission && (
                <p
                    role="note"
                    className="rounded-md bg-status-returned px-3 py-2 text-sm text-status-returned-foreground"
                >
                    最後の申請・再申請の後に、業務データの画面で更新されています。再申請で変更された項目は履歴の差分で確認できます(申請の時の値の全体は、段
                    2 では画面に表示しません)。
                </p>
            )}
            <p className="text-sm text-muted-foreground">
                業務モジュール：{module.name}
            </p>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                {plainFields.map((field) => (
                    <div
                        key={field.code}
                        className={cn(
                            'flex min-w-0 flex-col gap-1',
                            isWideField(field) && 'md:col-span-2',
                        )}
                    >
                        <dt className="text-xs text-muted-foreground">
                            <RecordFieldName field={field} />
                        </dt>
                        <dd>
                            <RecordValue
                                field={field}
                                value={record.values[field.code] ?? null}
                            />
                        </dd>
                    </div>
                ))}
            </dl>
            {detailsFields.map((field) => {
                const rows = record.details[field.code] ?? [];
                const columns = field.childModule?.fields ?? [];

                return (
                    <div key={field.code} className="flex flex-col gap-2">
                        <h3 className="text-sm font-medium">{field.name}</h3>
                        {rows.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                明細はありません
                            </p>
                        ) : (
                            <Table aria-label={field.name}>
                                <TableHeader>
                                    <TableRow>
                                        <RecordColumnHeads columns={columns} />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.id}>
                                            {columns.map((column) => (
                                                <TableCell
                                                    key={column.code}
                                                    className="wrap-anywhere whitespace-normal"
                                                >
                                                    <RecordValue
                                                        field={column}
                                                        value={
                                                            row.values[
                                                                column.code
                                                            ] ?? null
                                                        }
                                                        place="cell"
                                                    />
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </div>
                );
            })}
        </section>
    );
}
