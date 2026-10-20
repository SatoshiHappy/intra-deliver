import { RecordValue } from '@/components/RecordValue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import type {
    RecordFieldDefinition,
    RecordFieldValue,
    RecordModuleDefinition,
    WorkflowDiff,
} from '@/types';

/** 明細の行の変化の見出し */
const CHANGE_LABELS = { added: '追加', removed: '削除', changed: '変更' };

/** 差分の 1 行(項目・変更前・変更後) */
type DiffLine = {
    field: RecordFieldDefinition;
    before: RecordFieldValue;
    after: RecordFieldValue;
};

/**
 * 項目・変更前・変更後の表(値は RecordValue の差分の見え方 = docs の 設計/業務データの入れ物/画面/README.md 1.3・1.4。項目の名前は今の定義から)。
 */
function DiffLines({ lines, label }: { lines: DiffLine[]; label: string }) {
    return (
        <Table aria-label={label}>
            <TableHeader>
                <TableRow>
                    <TableHead>項目</TableHead>
                    <TableHead>変更前</TableHead>
                    <TableHead>変更後</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {lines.map((line) => (
                    <TableRow key={line.field.code}>
                        <TableCell>{line.field.name}</TableCell>
                        <TableCell className="wrap-anywhere whitespace-normal">
                            <RecordValue
                                field={line.field}
                                value={line.before}
                                place="diff"
                            />
                        </TableCell>
                        <TableCell className="wrap-anywhere whitespace-normal">
                            <RecordValue
                                field={line.field}
                                value={line.after}
                                place="diff"
                            />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * 項目のコード → 今の定義の項目(定義に無いコードは出さない = 読み取りも消した項目を差分にしない)。
 */
function fieldsByCode(
    module: RecordModuleDefinition | null,
): Map<string, RecordFieldDefinition> {
    return new Map((module?.fields ?? []).map((field) => [field.code, field]));
}

/**
 * 再申請の差分(docs の 設計/ワークフロー/画面.md 5 節の履歴): 題「直前の申請 <日時> との差分」、項目の表(項目・変更前・変更後)と、明細の項目ごとに
 * 行の「追加」「削除」「変更」を見出しにした表(変更は変わった項目だけ)。差分が無ければ「変更された項目はありません」。
 */
export function CaseDiffTable({
    diff,
    baseAt,
    module,
}: {
    diff: WorkflowDiff;
    baseAt: string | null;
    module: RecordModuleDefinition;
}) {
    const fields = fieldsByCode(module);
    const fieldLines = diff.fields.flatMap((change) => {
        const field = fields.get(change.code);

        return field === undefined ? [] : [{ field, ...change }];
    });
    const detailGroups = diff.details.flatMap((group) => {
        const field = fields.get(group.code);

        return field === undefined || group.rows.length === 0
            ? []
            : [{ field, rows: group.rows }];
    });
    const title = `直前の申請 ${baseAt === null ? EMPTY_VALUE : formatDatetime(baseAt)} との差分`;

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-3">
            <p className="text-sm font-medium">{title}</p>
            {fieldLines.length === 0 && detailGroups.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    変更された項目はありません
                </p>
            )}
            {fieldLines.length > 0 && (
                <DiffLines lines={fieldLines} label={title} />
            )}
            {detailGroups.map(({ field, rows }) => {
                const childFields = fieldsByCode(field.childModule);

                return (
                    <div key={field.code} className="flex flex-col gap-2">
                        <p className="text-sm font-medium">{field.name}</p>
                        {rows.map((row) => {
                            const codes =
                                row.change === 'changed'
                                    ? row.changedCodes
                                    : [...childFields.keys()];
                            const lines = codes.flatMap((code) => {
                                const child = childFields.get(code);

                                return child === undefined
                                    ? []
                                    : [
                                          {
                                              field: child,
                                              before:
                                                  row.before?.[code] ?? null,
                                              after: row.after?.[code] ?? null,
                                          },
                                      ];
                            });
                            const heading = CHANGE_LABELS[row.change];

                            return (
                                <div
                                    key={`${row.change}-${row.rowId}`}
                                    className="flex flex-col gap-1"
                                >
                                    <p className="text-xs text-muted-foreground">
                                        {heading}
                                    </p>
                                    <DiffLines
                                        lines={lines}
                                        label={`${field.name}の${heading}`}
                                    />
                                </div>
                            );
                        })}
                    </div>
                );
            })}
        </div>
    );
}
