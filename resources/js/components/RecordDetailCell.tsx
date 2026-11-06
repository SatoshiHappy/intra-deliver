import { TriangleAlert } from 'lucide-react';
import { CLEARED_FRAME_CLASS } from '@/components/ClearedNotice';
import { clearedReasonId, formErrorId } from '@/components/FormBlock';
import {
    hasCharacterCount,
    RecordFieldInput,
} from '@/components/RecordFieldInput';
import { Badge } from '@/components/ui/badge';
import { TableCell } from '@/components/ui/table';
import type { ClearedReason } from '@/lib/clearedFields';
import type { RecordFieldDraft, RecordRowDraft } from '@/lib/recordForm';
import { fieldErrorOf, referenceItemErrors } from '@/lib/recordFormErrors';
import { isRequiredNow } from '@/lib/recordRules';
import { cn } from '@/lib/utils';
import type { RecordFieldDefinition, RecordModuleDefinition } from '@/types';

export type DetailCellProps = {
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
    /** この行の空にしたセル(列のコード → 理由)。この列があれば注意の色の枠と ⚠(理由は表の上に 1 行) */
    clearedColumns: Record<string, ClearedReason> | undefined;
};

/** 空にしたセルの ⚠(読み上げない。理由は表の上の 1 行を入力の aria-describedby で読ませる) */
function ClearedCellIcon() {
    return (
        <TriangleAlert
            aria-hidden="true"
            className="mt-2 size-4 shrink-0 text-status-returned-foreground"
        />
    );
}

/**
 * 明細の表の上の、空にした理由の 1 行の id(明細の項目のコードと理由の組ごと。セルの入力の aria-describedby に使う)。
 */
export function detailClearedReasonId(
    detailsCode: string,
    reason: ClearedReason,
): string {
    return `${clearedReasonId(`record-field-${detailsCode}`)}-${reason}`;
}

/** セルの誤りの文言(入力の aria-describedby が指す id) */
function CellError({ id, error }: { id: string; error: string }) {
    return (
        <p
            id={formErrorId(id)}
            className="mt-1 text-xs whitespace-normal text-destructive"
        >
            {error}
        </p>
    );
}

/** 「必須」の札(文字数の出る列は入力の行の高さに揃える) */
function RequiredBadge({ counted }: { counted: boolean }) {
    return (
        <Badge variant="secondary" className={counted ? 'mt-2' : undefined}>
            必須
        </Badge>
    );
}

/**
 * 明細の行の 1 つの列のセル(入力・「必須」の札・誤りの文言。空にしたセルは注意の色の枠と ⚠)。
 */
export function DetailCell(props: DetailCellProps) {
    const { column, row, index } = props;
    const id = `record-detail-${props.detailsCode}-${row.key}-${column.code}`;
    const key = `details.${props.detailsCode}.${index}.values.${column.code}`;
    const error = fieldErrorOf(props.errors, key);
    const required = isRequiredNow(props.child, column, row.values);
    const counted = hasCharacterCount(column);
    // 文字数は入力の下に出るので、数のある列だけ札を入力の行の高さに揃える
    const align = counted ? 'items-start' : 'items-center';
    const reason = props.clearedColumns?.[column.code];
    const cleared = reason !== undefined;

    return (
        <TableCell className="align-top">
            <div
                className={cn(
                    'flex gap-2',
                    align,
                    cleared && `${CLEARED_FRAME_CLASS} p-1`,
                )}
            >
                {cleared && <ClearedCellIcon />}
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
                    clearedId={
                        reason &&
                        detailClearedReasonId(props.detailsCode, reason)
                    }
                />
                {required && <RequiredBadge counted={counted} />}
            </div>
            {error !== undefined && <CellError id={id} error={error} />}
        </TableCell>
    );
}
