import { useForm } from '@inertiajs/react';
import { FolderInput, Pencil, Plus, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, UnshownErrors } from '@/components/FormBlock';
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
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    addChoices,
    deleteOutcome,
    MODULE_CATEGORY_PROPS,
} from '@/lib/Records/moduleCategories';
import {
    destroy,
    modules as updateModules,
    store,
    update,
} from '@/routes/records/admin/categories';
import type { ModuleCategoryRow, UncategorizedModule } from '@/types';

/**
 * ツールバーのアイコンのボタン(Dialog を開く)。読み上げの名前とツールチップに文言を出す。disabled の間(分類を選ぶまで)は押せない。
 */
function ToolbarTrigger({
    label,
    icon,
    disabled = false,
}: {
    label: string;
    icon: ReactNode;
    disabled?: boolean;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <DialogTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={label}
                        disabled={disabled}
                    >
                        {icon}
                    </Button>
                </DialogTrigger>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

/**
 * モジュール分類の作成・編集の Dialog(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4、誤りの置き場 = 管理の画面.md 3 節の冒頭)。
 * nameMaxLength = 名前の文字数の上限(サーバーの値を props で受ける)。
 */
export function ModuleCategoryNameDialog(
    props: (
        | { mode: 'create' }
        | { mode: 'edit'; category: ModuleCategoryRow | null }
    ) & { nameMaxLength: number },
) {
    const category = props.mode === 'edit' ? props.category : null;
    const [open, setOpen] = useState(false);
    // 編集する分類の ID は開いたときのものを保つ(開いている間に選びが外れても、作成に変わらない)
    const [targetId, setTargetId] = useState<string | null>(null);
    const form = useForm({ name: '' });
    const id = `module-category-${props.mode}-name`;

    const changeOpen = (next: boolean) => {
        setOpen(next);

        if (next) {
            setTargetId(category?.id ?? null);
            form.setData({ name: category?.name ?? '' });
            form.clearErrors();
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (props.mode === 'edit' && targetId === null) {
            return;
        }

        form.submit(targetId === null ? store() : update(targetId), {
            only: MODULE_CATEGORY_PROPS,
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            {props.mode === 'create' ? (
                <ToolbarTrigger label="作成" icon={<Plus />} />
            ) : (
                <ToolbarTrigger
                    label="編集"
                    icon={<Pencil />}
                    disabled={category === null}
                />
            )}
            <DialogContent aria-describedby={undefined}>
                <form noValidate onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {props.mode === 'create'
                                ? 'モジュール分類の作成'
                                : 'モジュール分類の編集'}
                        </DialogTitle>
                    </DialogHeader>
                    <UnshownErrors errors={form.errors} shownKeys={['name']} />
                    <FormBlock
                        id={id}
                        label="名前"
                        isRequired
                        error={form.errors.name}
                    >
                        <CountedTextInput
                            id={id}
                            value={form.data.name}
                            max={props.nameMaxLength}
                            invalid={form.errors.name !== undefined}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                    </FormBlock>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            キャンセル
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {props.mode === 'create' ? '作成' : '更新'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * モジュール分類の削除の確認(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4)。
 * 開いたときの分類を保つ(消した後に選びが外れても、閉じるまでの表示が変わらない)。
 */
export function ModuleCategoryDeleteDialog({
    category,
    categories,
}: {
    category: ModuleCategoryRow | null;
    categories: ModuleCategoryRow[];
}) {
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState<ModuleCategoryRow | null>(null);
    const form = useForm({});

    const changeOpen = (next: boolean) => {
        setOpen(next);

        if (next) {
            setTarget(category);
            form.clearErrors();
        }
    };

    const remove = () => {
        if (target === null) {
            return;
        }

        form.submit(destroy(target.id), {
            only: MODULE_CATEGORY_PROPS,
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    // 件数は今の props で数える(開いた後に読み直したとき = 422 の後などに合わせる)。分類が props から消えたら開いたときの分類で数える
    const current =
        target === null
            ? null
            : (categories.find((item) => item.id === target.id) ?? target);
    const outcome =
        current === null ? null : deleteOutcome(current, categories);

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <ToolbarTrigger
                label="削除"
                icon={<Trash2 />}
                disabled={category === null}
            />
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>モジュール分類を削除しますか</DialogTitle>
                </DialogHeader>
                <UnshownErrors errors={form.errors} shownKeys={[]} />
                {current !== null && outcome !== null && (
                    <DialogDescription className="flex flex-col gap-1">
                        <span>『{current.name}』を削除します。</span>
                        {outcome.moved > 0 && (
                            <span>
                                {outcome.moved}{' '}
                                件は「業務」の直下の末尾に移動します。
                            </span>
                        )}
                        {outcome.kept > 0 && (
                            <span>
                                {outcome.kept}{' '}
                                件はほかのモジュール分類に引き続き分類されます。
                            </span>
                        )}
                    </DialogDescription>
                )}
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setOpen(false)}
                    >
                        キャンセル
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={form.processing}
                        onClick={remove}
                    >
                        削除
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * 「業務モジュールの追加」の Dialog(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4、業務処理.md 3.10)。
 * 422 の後の読み直しではチェックを付け直さない(利用者が選んだ状態を残す)。開いたときの分類を保つ。
 */
export function ModuleCategoryModulesDialog({
    category,
    categories,
    uncategorized,
}: {
    category: ModuleCategoryRow | null;
    categories: ModuleCategoryRow[];
    uncategorized: UncategorizedModule[];
}) {
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState<ModuleCategoryRow | null>(null);
    const form = useForm<{ codes: string[] }>({ codes: [] });
    const choices = addChoices(categories, uncategorized);
    const id = 'module-category-modules';

    const changeOpen = (next: boolean) => {
        setOpen(next);

        if (next) {
            setTarget(category);
            form.setData({
                codes: category?.modules.map((module) => module.code) ?? [],
            });
            form.clearErrors();
        }
    };

    const check = (code: string, isChecked: boolean) =>
        form.setData(
            'codes',
            isChecked
                ? [...form.data.codes.filter((item) => item !== code), code]
                : form.data.codes.filter((item) => item !== code),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (target === null) {
            return;
        }

        // 一覧の順に送る(一覧に無いコード = 開いた後に一覧から消えたものは、チェックした順に後ろへ)
        const order = choices.map((choice) => choice.code);
        form.transform((data) => ({
            codes: [
                ...order.filter((code) => data.codes.includes(code)),
                ...data.codes.filter((code) => !order.includes(code)),
            ],
        }));
        form.submit(updateModules(target.id), {
            only: MODULE_CATEGORY_PROPS,
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    // 名前は今の props で読む。分類が props から消えたら開いたときの分類の名前
    const name =
        target === null
            ? ''
            : (categories.find((item) => item.id === target.id) ?? target).name;

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <ToolbarTrigger
                label="業務モジュールの追加"
                icon={<FolderInput />}
                disabled={category === null}
            />
            <DialogContent className="flex max-h-[90vh] flex-col">
                <form
                    noValidate
                    onSubmit={submit}
                    className="flex min-h-0 flex-col gap-4"
                >
                    <DialogHeader>
                        <DialogTitle>業務モジュールの追加</DialogTitle>
                        <DialogDescription>
                            『{name}
                            』に追加する業務モジュールを選択してください。選択を解除した業務モジュールは『
                            {name}
                            』から除外され、ほかのモジュール分類に属さない場合は「業務」の直下の末尾に移動します。非表示の業務モジュールは一覧に表示されません。
                        </DialogDescription>
                    </DialogHeader>
                    <UnshownErrors errors={form.errors} shownKeys={[]} />
                    <div
                        role="group"
                        aria-labelledby={`${id}-label`}
                        className="flex min-h-0 flex-col gap-2"
                    >
                        <p id={`${id}-label`} className="text-sm font-medium">
                            業務モジュール
                        </p>
                        {choices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                表示中の業務モジュールはありません
                            </p>
                        ) : (
                            <ul className="flex max-h-[60vh] min-h-0 flex-col gap-2 overflow-y-auto">
                                {choices.map((choice) => (
                                    <li
                                        key={choice.code}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`${id}-${choice.code}`}
                                            checked={form.data.codes.includes(
                                                choice.code,
                                            )}
                                            onCheckedChange={(next) =>
                                                check(
                                                    choice.code,
                                                    next === true,
                                                )
                                            }
                                        />
                                        <Label htmlFor={`${id}-${choice.code}`}>
                                            {choice.name}
                                        </Label>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            キャンセル
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || choices.length === 0}
                        >
                            更新
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
