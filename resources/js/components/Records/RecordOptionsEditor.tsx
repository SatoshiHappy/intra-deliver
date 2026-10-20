import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { formErrorId, RequiredMark } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCodeCheck } from '@/hooks/Records/use-code-check';
import { optionCodeError } from '@/lib/Records/recordDefinitionCode';
import type { OptionDraft } from '@/lib/Records/recordFieldForm';
import { newOption } from '@/lib/Records/recordFieldForm';
import type { DefinitionTextMaxLengths } from '@/types';

type Props = {
    options: OptionDraft[];
    onChange: (options: OptionDraft[]) => void;
    /** 検証のエラー(キー → 文言。options・options.<添字>(行の形 = 入口)・options.<添字>.code・options.<添字>.name) */
    errors: Record<string, string | undefined>;
    /** コード・名称の文字数の上限(code・name を使う) */
    textMaxLengths: DefinitionTextMaxLengths;
    /** 選択肢のコードのサーバーの誤り(options.<添字>.code)を消す(コードを変えたとき) */
    onCodeEdited: (index: number) => void;
    /** 行の並びが変わった(上へ・下へ・削除)。サーバーの誤りのキーは送った時点の添字で、並びが変わると意味を失うので、呼び手が options.* を消す */
    onRowsReordered: () => void;
};

type OptionRowProps = {
    option: OptionDraft;
    index: number;
    count: number;
    errors: Record<string, string | undefined>;
    textMaxLengths: DefinitionTextMaxLengths;
    onPatch: (patch: Partial<OptionDraft>) => void;
    onMove: (to: number) => void;
    onRemove: () => void;
    onCodeEdited: () => void;
};

/**
 * 選択肢の表の 1 行。コードは、新しい行だけ欄を離れたときから書式を検査する(作った行はコードを変えられないので検査しない。サーバーの誤りが先)。
 */
function OptionRow({
    option,
    index,
    count,
    errors,
    textMaxLengths,
    onPatch,
    onMove,
    onRemove,
    onCodeEdited,
}: OptionRowProps) {
    const position = `(${index + 1} 行目)`;
    const codeCheck = useCodeCheck({
        value: option.code,
        serverError: errors[`options.${index}.code`],
        clearServerError: onCodeEdited,
        check: option.isSaved ? () => null : optionCodeError,
    });
    const codeError = codeCheck.shownError;
    const codeId = `option-${option.key}-code`;
    const nameId = `option-${option.key}-name`;
    const nameError = errors[`options.${index}.name`];
    const rowError = errors[`options.${index}`];

    return (
        <TableRow>
            <TableCell className="align-top">
                <CountedTextInput
                    id={codeId}
                    aria-label={`選択肢のコード${position}`}
                    value={option.code}
                    max={textMaxLengths.code}
                    invalid={codeError !== undefined}
                    countClassName="mt-1"
                    readOnly={option.isSaved}
                    onChange={(event) => {
                        onPatch({ code: event.target.value });
                        codeCheck.onChange();
                    }}
                    onBlur={codeCheck.onBlur}
                />
                {codeError !== undefined && (
                    <p
                        id={formErrorId(codeId)}
                        className="mt-1 text-xs whitespace-normal text-destructive"
                    >
                        {codeError}
                    </p>
                )}
            </TableCell>
            <TableCell className="align-top">
                <CountedTextInput
                    id={nameId}
                    aria-label={`選択肢の名称${position}`}
                    value={option.name}
                    max={textMaxLengths.name}
                    invalid={nameError !== undefined}
                    countClassName="mt-1"
                    onChange={(event) => onPatch({ name: event.target.value })}
                />
                {nameError !== undefined && (
                    <p
                        id={formErrorId(nameId)}
                        className="mt-1 text-xs whitespace-normal text-destructive"
                    >
                        {nameError}
                    </p>
                )}
            </TableCell>
            <TableCell className="align-top">
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`上へ${position}`}
                        disabled={index === 0}
                        onClick={() => onMove(index - 1)}
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`下へ${position}`}
                        disabled={index === count - 1}
                        onClick={() => onMove(index + 1)}
                    >
                        <ArrowDown />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`選択肢を削除${position}`}
                        onClick={onRemove}
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
}

/**
 * 選択肢の表(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.4、必須の入力の印 = 画面/README.md 5 節)。
 * 並びは表の順で送る(業務処理が配列の添字を並び順にする)。
 */
export function RecordOptionsEditor({
    options,
    onChange,
    errors,
    textMaxLengths,
    onCodeEdited,
    onRowsReordered,
}: Props) {
    const move = (from: number, to: number) => {
        const moved = [...options];
        const [option] = moved.splice(from, 1);
        moved.splice(to, 0, option);
        onChange(moved);
        onRowsReordered();
    };
    const remove = (index: number) => {
        onChange(options.filter((_, at) => at !== index));
        onRowsReordered();
    };
    const change = (index: number, patch: Partial<OptionDraft>) =>
        onChange(
            options.map((option, position) =>
                position === index ? { ...option, ...patch } : option,
            ),
        );

    return (
        <div role="group" aria-label="選択肢" className="flex flex-col gap-2">
            <span className="text-sm font-medium">選択肢</span>
            {errors.options !== undefined && (
                <p className="text-sm text-destructive">{errors.options}</p>
            )}
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>
                            <span className="flex items-center gap-2">
                                コード
                                <RequiredMark />
                            </span>
                        </TableHead>
                        <TableHead>
                            <span className="flex items-center gap-2">
                                名称
                                <RequiredMark />
                            </span>
                        </TableHead>
                        <TableHead className="w-0">
                            <span className="sr-only">操作</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {options.map((option, index) => (
                        <OptionRow
                            key={option.key}
                            option={option}
                            index={index}
                            count={options.length}
                            errors={errors}
                            textMaxLengths={textMaxLengths}
                            onPatch={(patch) => change(index, patch)}
                            onMove={(to) => move(index, to)}
                            onRemove={() => remove(index)}
                            onCodeEdited={() => onCodeEdited(index)}
                        />
                    ))}
                </TableBody>
            </Table>
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...options, newOption()])}
                >
                    <Plus />
                    選択肢を追加
                </Button>
            </div>
        </div>
    );
}
