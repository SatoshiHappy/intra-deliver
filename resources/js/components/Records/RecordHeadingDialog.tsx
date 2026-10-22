import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, UnshownErrors } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { store, update } from '@/routes/records/admin/headings';

type Props = {
    /** 見出しを足す・変える業務モジュールのコード */
    moduleCode: string;
    /** 編集する見出し(追加 = null) */
    heading: { id: number; name: string } | null;
    /** 名前の文字数の上限(サーバーの上限を props で受ける) */
    nameMaxLength: number;
};

/**
 * 名前の欄(必須。下に表示先の説明と文字数)。
 */
function HeadingNameField({
    id,
    value,
    max,
    error,
    onChange,
}: {
    id: string;
    value: string;
    max: number;
    error: string | undefined;
    onChange: (name: string) => void;
}) {
    return (
        <FormBlock
            id={id}
            label="名前"
            isRequired
            hint="詳細の画面と、登録・編集の画面に見出しとして表示されます。"
            error={error}
        >
            <CountedTextInput
                id={id}
                value={value}
                max={max}
                invalid={error !== undefined}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormBlock>
    );
}

/**
 * Dialog の題と説明(追加と編集で説明は同じ)。
 */
function HeadingFormHeader({ isNew }: { isNew: boolean }) {
    return (
        <DialogHeader>
            <DialogTitle>{isNew ? '見出しを追加' : '見出しの編集'}</DialogTitle>
            <DialogDescription>
                項目の並びに見出しを追加します。見出しの後ろの項目が、次の見出しの前までその見出しのまとまりになります。
            </DialogDescription>
        </DialogHeader>
    );
}

/**
 * Dialog の中のフォーム(開くたびに作り直す = 入力も誤りも開く前の状態から始まる)。名前の誤りは欄の下(キー name)、ほかの誤り
 * (明細のモジュールへの追加 = module、並び順の上限 = sort_key)は先頭に出す。
 */
function HeadingForm({
    moduleCode,
    heading,
    nameMaxLength,
    onDone,
    onCancel,
}: Props & { onDone: () => void; onCancel: () => void }) {
    const form = useForm({ name: heading?.name ?? '' });
    const errors = form.errors as Record<string, string | undefined>;
    const id =
        heading === null ? 'heading-new-name' : `heading-${heading.id}-name`;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            heading === null
                ? store(moduleCode)
                : update({ module: moduleCode, heading: heading.id }),
            { preserveScroll: true, onSuccess: onDone },
        );
    };

    return (
        <form noValidate onSubmit={submit} className="grid gap-4">
            <HeadingFormHeader isNew={heading === null} />
            <UnshownErrors errors={errors} shownKeys={['name']} />
            <HeadingNameField
                id={id}
                value={form.data.name}
                max={nameMaxLength}
                error={errors.name}
                onChange={(name) => form.setData('name', name)}
            />
            <DialogFooter>
                <Button type="button" variant="outline" onClick={onCancel}>
                    キャンセル
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {heading === null ? '追加' : '更新'}
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * 見出しの追加・編集の Dialog(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 5 節。追加と編集で同じ)。
 * trigger = Dialog を開くボタン。
 */
export function RecordHeadingDialog({
    trigger,
    ...props
}: Props & { trigger: ReactNode }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <HeadingForm
                    {...props}
                    onDone={() => setOpen(false)}
                    onCancel={() => setOpen(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
