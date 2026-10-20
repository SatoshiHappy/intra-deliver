import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { toastErrors } from '@/lib/flashToast';
import type { RouteDefinition } from '@/wayfinder';

type Props = {
    /** 開くボタンの見た目の文字(省くと「削除」。ワークフローの一時保存の画面 =「一時保存を削除」) */
    triggerText?: string;
    /** 開くボタンの読み上げの名前(省くと見た目の文字) */
    triggerLabel?: string;
    /** 開くボタンの見た目(詳細の画面の「削除」= destructive、表の行の操作 = ghost) */
    triggerVariant?: 'destructive' | 'ghost';
    title: string;
    description: string;
    /** 削除の後も今のスクロール位置を保つか(同じ画面に戻る管理の表 = true、別の画面へ移るレコードの削除 = false。既定 false) */
    preserveScroll?: boolean;
    /** 削除の送り先(records.data.destroy・records.admin.fields.destroy・records.admin.rules.destroy・workflow.drafts.destroy) */
    action: RouteDefinition<'delete'>;
    /** 本文で送る値(ワークフローの一時保存の削除 = 画面を開いた時の改訂番号 { revision }。省くと送らない) */
    data?: Record<string, string | number>;
};

/**
 * 削除の確かめ(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.4・設計/業務データの入れ物/画面/管理の画面.md 3.3。レコード・項目・規則で 1 つ = 修正方針 C5): Dialog に影響を書き、
 * 「削除」(destructive)で送る。成功は移り先の画面へ(トーストはサーバーの flash。Dialog も閉じる)。業務処理が拒んだとき
 * (参照されているレコード = キー record、規則が使う項目 = キー field)は Dialog を閉じ、文言をトーストで出す。
 */
export function ConfirmDeleteDialog({
    triggerText = '削除',
    triggerLabel,
    triggerVariant = 'ghost',
    preserveScroll = false,
    title,
    description,
    action,
    data,
}: Props) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const remove = () =>
        router.delete(action.url, {
            data,
            preserveScroll,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setOpen(false),
            onError: (errors) => {
                setOpen(false);
                toastErrors(errors);
            },
        });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={triggerVariant}
                    size={triggerVariant === 'ghost' ? 'sm' : 'default'}
                    aria-label={triggerLabel}
                >
                    {triggerText}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">キャンセル</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={remove}
                    >
                        削除
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
