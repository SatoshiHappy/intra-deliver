import { useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { UnshownErrors } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    MAX_RELATED_COLUMNS,
    movedColumns,
    toggledColumns,
} from '@/lib/recordRelated';
import { update } from '@/routes/records/admin/related-columns';
import type { RecordRelatedColumnSetting } from '@/types';

type Props = {
    /** 行の業務モジュール(参照元)のコード */
    moduleCode: string;
    setting: RecordRelatedColumnSetting;
};

/**
 * 選べる項目のチェックの一覧(6 つ目は押せない形 = 業務モジュールの管理.md の 4 節)。
 */
function CandidateList({
    setting,
    selected,
    onChange,
}: {
    setting: RecordRelatedColumnSetting;
    selected: string[];
    onChange: (selected: string[]) => void;
}) {
    const full = selected.length >= MAX_RELATED_COLUMNS;

    return (
        <ul aria-label="選べる項目" className="flex flex-col gap-2">
            {setting.candidates.map((candidate) => {
                const id = `related-${setting.entry}-${candidate.code}`;
                const checked = selected.includes(candidate.code);

                return (
                    <li
                        key={candidate.code}
                        className="flex items-center gap-2"
                    >
                        <Checkbox
                            id={id}
                            checked={checked}
                            disabled={!checked && full}
                            onCheckedChange={() =>
                                onChange(
                                    toggledColumns(selected, candidate.code),
                                )
                            }
                        />
                        <Label htmlFor={id}>{candidate.name}</Label>
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * 選んだ列の並び(上へ・下へ)。
 */
function SelectedList({
    setting,
    selected,
    onChange,
}: {
    setting: RecordRelatedColumnSetting;
    selected: string[];
    onChange: (selected: string[]) => void;
}) {
    const nameOf = (code: string) =>
        setting.candidates.find((candidate) => candidate.code === code)?.name ??
        code;

    return (
        <ol aria-label="選んだ列" className="flex flex-col gap-1">
            {selected.map((code, index) => (
                <li key={code} className="flex items-center gap-2 text-sm">
                    <span className="w-5 font-num text-muted-foreground tabular-nums">
                        {index + 1}
                    </span>
                    <span className="flex-1">{nameOf(code)}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`上へ(${nameOf(code)})`}
                        disabled={index === 0}
                        onClick={() =>
                            onChange(movedColumns(selected, index, -1))
                        }
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`下へ(${nameOf(code)})`}
                        disabled={index === selected.length - 1}
                        onClick={() =>
                            onChange(movedColumns(selected, index, 1))
                        }
                    >
                        <ArrowDown />
                    </Button>
                </li>
            ))}
        </ol>
    );
}

/**
 * Dialog の中のフォーム(開くたびに作り直す)。誤り(列 = columns、明細のモジュール = module)は先頭に出す。
 */
function ColumnsForm({
    moduleCode,
    setting,
    onDone,
}: Props & { onDone: () => void }) {
    const form = useForm<{ entry: string; columns: string[] }>({
        entry: setting.entry,
        columns: setting.columns.map((column) => column.code),
    });
    const change = (columns: string[]) => form.setData('columns', columns);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(update(moduleCode), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form noValidate onSubmit={submit} className="grid gap-4">
            <DialogHeader>
                <DialogTitle>関連レコードの列の選択</DialogTitle>
                <DialogDescription>
                    参照先のレコードの詳細に表示する列を、5
                    つまで選択して並べます。
                </DialogDescription>
            </DialogHeader>
            <UnshownErrors errors={form.errors} shownKeys={[]} />
            <div className="grid gap-6 sm:grid-cols-2">
                <CandidateList
                    setting={setting}
                    selected={form.data.columns}
                    onChange={change}
                />
                <SelectedList
                    setting={setting}
                    selected={form.data.columns}
                    onChange={change}
                />
            </div>
            <ColumnsButtons
                canReset={form.data.columns.length > 0}
                processing={form.processing}
                onReset={() => change([])}
                onCancel={onDone}
            />
        </form>
    );
}

/** ボタンの列(「未選択に戻す」= 選んだ列を全部外す。主ボタン「保存」が右端) */
function ColumnsButtons({
    canReset,
    processing,
    onReset,
    onCancel,
}: {
    canReset: boolean;
    processing: boolean;
    onReset: () => void;
    onCancel: () => void;
}) {
    return (
        <DialogFooter>
            <Button
                type="button"
                variant="outline"
                className="sm:mr-auto"
                disabled={!canReset}
                onClick={onReset}
            >
                未選択に戻す
            </Button>
            <Button type="button" variant="outline" onClick={onCancel}>
                キャンセル
            </Button>
            <Button type="submit" disabled={processing}>
                保存
            </Button>
        </DialogFooter>
    );
}

/**
 * 関連レコードの列の選択の Dialog(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 4 節)。trigger = 開くボタン。
 */
export function RecordRelatedColumnsDialog({
    trigger,
    ...props
}: Props & { trigger: ReactNode }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <ColumnsForm {...props} onDone={() => setOpen(false)} />
            </DialogContent>
        </Dialog>
    );
}
