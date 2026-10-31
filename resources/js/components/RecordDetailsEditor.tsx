import { ArrowDown, ArrowUp, Trash2 } from 'lucide-react';
import { formErrorId } from '@/components/FormBlock';
import {
    hasCharacterCount,
    RecordFieldInput,
} from '@/components/RecordFieldInput';
import { RecordColumnHeads } from '@/components/RecordFieldBadges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { RecordFieldDraft, RecordRowDraft } from '@/lib/recordForm';
import { MAX_DETAIL_ROWS, newRow, valueFields } from '@/lib/recordForm';
import { fieldErrorOf, referenceItemErrors } from '@/lib/recordFormErrors';
import { isRequiredNow } from '@/lib/recordRules';
import { cn } from '@/lib/utils';
import type { RecordFieldDefinition, RecordModuleDefinition } from '@/types';

type Props = {
    /** 明細の項目(childModule を持つ) */
    field: RecordFieldDefinition;
    /** 書くレコードの id(行の他モジュール参照の候補から除く = 明細の行の親。null = 除かない) */
    recordId: number | null;
    rows: RecordRowDraft[];
    onChange: (rows: RecordRowDraft[]) => void;
    /**
     * 行の並びが変わった(上へ・下へ・行を削除)か、行の複数の参照の欄のチップを外した。添字のキーの誤りを消すため、
     * 表の添字のキーの頭(`details.<コード>.`)か、その欄のキーの頭を渡す
     */
    onRowsReordered: (prefix: string) => void;
    /** 明細の項目が今の入力で必須か(1 行以上) */
    isRequiredNow: boolean;
    /** 検証のエラー(キー → 文言) */
    errors: Record<string, string | undefined>;
};

type DetailCellProps = {
    /** 明細の項目のコード */
    detailsCode: string;
    /** 明細のモジュール(行の必須の評価) */
    child: RecordModuleDefinition;
    column: RecordFieldDefinition;
    row: RecordRowDraft;
    /** 行の添字(誤りのキー) */
    index: number;
    recordId: number | null;
    errors: Record<string, string | undefined>;
    onChange: (draft: RecordFieldDraft) => void;
    /** prefix で始まるキーの誤りを消す */
    onClearErrors: (prefix: string) => void;
};

/**
 * 行の 1 つの列のセル(入力・「必須」の札・誤りの文言)。
 */
function DetailCell(props: DetailCellProps) {
    const { column, row, index } = props;
    const id = `record-detail-${props.detailsCode}-${row.key}-${column.code}`;
    const key = `details.${props.detailsCode}.${index}.values.${column.code}`;
    const error = fieldErrorOf(props.errors, key);
    const required = isRequiredNow(props.child, column, row.values);
    const counted = hasCharacterCount(column);
    // 文字数は入力の下に出るので、数のある列だけ札を入力の行の高さに揃える
    const align = counted ? 'items-start' : 'items-center';

    return (
        <TableCell className="align-top">
            <div className={cn('flex gap-2', align)}>
                <RecordFieldInput
                    id={id}
                    field={column}
                    recordId={props.recordId}
                    draft={row.values[column.code] ?? null}
                    onChange={props.onChange}
                    isRequiredNow={required}
                    invalid={error !== undefined}
                    itemErrors={referenceItemErrors(
                        props.errors,
                        key,
                        props.onClearErrors,
                    )}
                    ariaLabel={`${column.name}(${index + 1} 行目)`}
                />
                {required && (
                    <Badge
                        variant="secondary"
                        className={counted ? 'mt-2' : undefined}
                    >
                        必須
                    </Badge>
                )}
            </div>
            {error !== undefined && (
                <p
                    id={formErrorId(id)}
                    className="mt-1 text-xs whitespace-normal text-destructive"
                >
                    {error}
                </p>
            )}
        </TableCell>
    );
}

/**
 * 明細の表(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2 の「明細の表」と「エラー」)。
 */
export function RecordDetailsEditor({
    field,
    recordId,
    rows,
    onChange,
    onRowsReordered,
    isRequiredNow: tableRequired,
    errors,
}: Props) {
    const child = field.childModule;

    if (child === null) {
        return null;
    }

    const columns = valueFields(child);
    const headingId = `record-field-${field.code}-label`;
    const tableErrorId = formErrorId(`record-field-${field.code}`);
    const tableError = errors[`details.${field.code}`];
    const rowsPrefix = `details.${field.code}.`;
    const move = (from: number, to: number) => {
        const moved = [...rows];
        const [row] = moved.splice(from, 1);
        moved.splice(to, 0, row);
        onChange(moved);
        onRowsReordered(rowsPrefix);
    };
    const remove = (index: number) => {
        onChange(rows.filter((_, position) => position !== index));
        onRowsReordered(rowsPrefix);
    };
    const changeValue = (
        index: number,
        code: string,
        draft: RecordFieldDraft,
    ) =>
        onChange(
            rows.map((row, position) =>
                position === index
                    ? { ...row, values: { ...row.values, [code]: draft } }
                    : row,
            ),
        );

    return (
        <div
            role="group"
            aria-labelledby={headingId}
            className="flex flex-col gap-2"
        >
            <div className="flex items-center gap-2">
                <span id={headingId} className="text-sm font-medium">
                    {field.name}
                </span>
                {tableRequired && <Badge variant="secondary">必須</Badge>}
            </div>
            {tableError !== undefined && (
                <p id={tableErrorId} className="text-sm text-destructive">
                    {tableError}
                </p>
            )}
            <Table>
                <TableHeader>
                    <TableRow>
                        <RecordColumnHeads columns={columns} />
                        <TableHead className="w-0">
                            <span className="sr-only">操作</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.length === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={columns.length + 1}
                                className="text-center text-muted-foreground"
                            >
                                明細はありません
                            </TableCell>
                        </TableRow>
                    )}
                    {rows.map((row, index) => {
                        const rowKey = `details.${field.code}.${index}`;
                        const rowError = errors[`${rowKey}.id`];

                        return (
                            <TableRow key={row.key}>
                                {columns.map((column) => (
                                    <DetailCell
                                        key={column.code}
                                        detailsCode={field.code}
                                        child={child}
                                        column={column}
                                        row={row}
                                        index={index}
                                        recordId={recordId}
                                        errors={errors}
                                        onChange={(draft) =>
                                            changeValue(
                                                index,
                                                column.code,
                                                draft,
                                            )
                                        }
                                        onClearErrors={onRowsReordered}
                                    />
                                ))}
                                <TableCell className="align-top">
                                    <div className="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`上へ(${index + 1} 行目)`}
                                            disabled={index === 0}
                                            onClick={() =>
                                                move(index, index - 1)
                                            }
                                        >
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`下へ(${index + 1} 行目)`}
                                            disabled={index === rows.length - 1}
                                            onClick={() =>
                                                move(index, index + 1)
                                            }
                                        >
                                            <ArrowDown />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`行を削除(${index + 1} 行目)`}
                                            onClick={() => remove(index)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    {rowError !== undefined && (
                                        <p className="mt-1 text-xs whitespace-normal text-destructive">
                                            {rowError}
                                        </p>
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={rows.length >= MAX_DETAIL_ROWS}
                    aria-invalid={tableError !== undefined || undefined}
                    aria-describedby={
                        tableError === undefined ? undefined : tableErrorId
                    }
                    onClick={() => onChange([...rows, newRow(child)])}
                >
                    行を追加
                </Button>
            </div>
        </div>
    );
}
