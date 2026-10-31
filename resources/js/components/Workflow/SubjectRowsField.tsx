import { SubjectRowPicker } from '@/components/Workflow/SubjectRowPicker';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formErrorId } from '@/components/FormBlock';
import {
    SUBJECT_TYPES,
    withRowPicked,
    withRowType,
    withoutRow,
} from '@/lib/Workflow/subjectRows';
import type { SubjectRowDraft } from '@/lib/Workflow/subjectRows';

type Props = {
    /** 欄の id の接頭辞(行ごとの id と誤りの文言の id を作る) */
    idPrefix: string;
    /** 表の読み上げの名前 */
    label: string;
    rows: SubjectRowDraft[];
    errors: Record<string, string | undefined>;
    /** 誤りのキー(並び全体のキー。行は <キー>.<添字>) */
    errorKey: string;
    onChange: (rows: SubjectRowDraft[]) => void;
};

type LineProps = {
    row: SubjectRowDraft;
    number: number;
    rows: SubjectRowDraft[];
    onChange: (rows: SubjectRowDraft[]) => void;
};

/**
 * 行の種類の Select(替えると相手は選び直し)。
 */
function RowTypeSelect({ row, number, rows, onChange }: LineProps) {
    return (
        <Select
            value={row.type}
            onValueChange={(next) => {
                const type = SUBJECT_TYPES.find(
                    (item) => item.value === next,
                )?.value;

                if (type !== undefined) {
                    onChange(withRowType(rows, row.key, type));
                }
            }}
        >
            <SelectTrigger
                aria-label={`対象者の種類 ${number} 行目`}
                className="w-40"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {SUBJECT_TYPES.map((item) => (
                    <SelectItem key={item.value} value={item.value}>
                        {item.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * 行の対象者の欄(候補の検索。行の誤りは欄の下に出す)。
 */
function RowTarget({
    idPrefix,
    error,
    ...line
}: LineProps & { idPrefix: string; error: string | undefined }) {
    const { row, number, rows, onChange } = line;
    const id = `${idPrefix}-${row.key}-target`;

    return (
        <div className="flex flex-col gap-1">
            <SubjectRowPicker
                key={row.type}
                id={id}
                type={row.type}
                name={row.name}
                label={`対象者 ${number} 行目`}
                error={error}
                onPick={(picked) =>
                    onChange(withRowPicked(rows, row.key, picked))
                }
            />
            {error !== undefined && (
                <p id={formErrorId(id)} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * 対象者の行 1 つ(種類の Select・対象者の候補の検索・行を削除)。
 */
function SubjectRowLine({
    idPrefix,
    error,
    ...line
}: LineProps & { idPrefix: string; error: string | undefined }) {
    const { row, number, rows, onChange } = line;

    return (
        <TableRow>
            <TableCell className="align-top">
                <RowTypeSelect {...line} />
            </TableCell>
            <TableCell className="align-top whitespace-normal">
                <RowTarget idPrefix={idPrefix} error={error} {...line} />
            </TableCell>
            <TableCell className="align-top">
                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={`対象者 ${number} 行目を削除`}
                        onClick={() => onChange(withoutRow(rows, row.key))}
                    >
                        行を削除
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * 対象者の行の表(列 = 対象者の種類・対象者・操作。docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節・3.4 節)。
 * 参照者のタブとステップの設定の「対象者で指定」が使う。行の追加は呼び手の見出しのボタン。
 * 誤りのキー = 並び全体は errorKey、行は <errorKey>.<添字>。
 */
export function SubjectRowsField({
    idPrefix,
    label,
    rows,
    errors,
    errorKey,
    onChange,
}: Props) {
    return (
        <div className="flex flex-col gap-2">
            <Table aria-label={label}>
                <TableHeader>
                    <TableRow>
                        <TableHead>対象者の種類</TableHead>
                        <TableHead>対象者</TableHead>
                        <TableHead>
                            <span className="sr-only">操作</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row, index) => (
                        <SubjectRowLine
                            key={row.key}
                            row={row}
                            number={index + 1}
                            idPrefix={idPrefix}
                            error={errors[`${errorKey}.${index}`]}
                            rows={rows}
                            onChange={onChange}
                        />
                    ))}
                </TableBody>
            </Table>
            {errors[errorKey] !== undefined && (
                <p className="text-sm text-destructive">{errors[errorKey]}</p>
            )}
        </div>
    );
}
