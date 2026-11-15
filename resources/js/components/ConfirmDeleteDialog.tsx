import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { RefObject } from 'react';
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

type Common = {
    title: string;
    description: string;
    /** 削除の後も今のスクロール位置を保つか(同じ画面に戻る管理の表 = true、別の画面へ移るレコードの削除 = false。既定 false) */
    preserveScroll?: boolean;
    /**
     * 削除の送り先(records.data.destroy・records.admin.modules.destroy-field・records.admin.modules.destroy-rule・
     * workflow.drafts.destroy)
     */
    action: RouteDefinition<'delete'>;
    /** 本文で送る値(ワークフローの一時保存の削除 = 画面を開いた時の改訂番号 { revision }。省くと送らない) */
    data?: Record<string, string | number>;
};

/** 開閉を内側で持ち、開くボタンを出す形 */
type WithTrigger = Common & {
    /** 開くボタンの見た目の文字(省くと「削除」。ワークフローの一時保存の画面 =「一時保存を削除」) */
    triggerText?: string;
    /** 開くボタンの読み上げの名前(省くと見た目の文字) */
    triggerLabel?: string;
    /** 開くボタンの見た目(詳細の画面の「削除」= destructive、表の行の操作 = outline sm) */
    triggerVariant?: 'destructive' | 'outline';
    open?: never;
    onOpenChange?: never;
    returnFocusTo?: never;
};

/** 開閉を呼び手が持つ形(申請の詳細のメニューの項目から開く。開くボタンは呼び手の側にある) */
type Controlled = Common & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** 閉じた後にフォーカスを戻す要素(残っていれば) */
    returnFocusTo?: RefObject<HTMLElement | null>;
    triggerText?: never;
    triggerLabel?: never;
    triggerVariant?: never;
};

type Props = WithTrigger | Controlled;

/** 開くボタン(開閉を内側で持つ形だけ) */
function DeleteTrigger({
    triggerText = '削除',
    triggerLabel,
    triggerVariant = 'outline',
}: Pick<WithTrigger, 'triggerText' | 'triggerLabel' | 'triggerVariant'>) {
    return (
        <DialogTrigger asChild>
            <Button
                variant={triggerVariant}
                size={triggerVariant === 'outline' ? 'sm' : 'default'}
                aria-label={triggerLabel}
            >
                {triggerText}
            </Button>
        </DialogTrigger>
    );
}

/** 閉じた後のフォーカスを target へ(DOM に残っていれば)。省くと Dialog の既定(開いたボタンへ) */
function focusBackTo(target: RefObject<HTMLElement | null> | undefined) {
    if (target === undefined) {
        return undefined;
    }

    return (event: Event) => {
        event.preventDefault();

        if (target.current?.isConnected === true) {
            target.current.focus();
        }
    };
}

/**
 * 削除の確かめ(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.4・設計/業務データの入れ物/画面/管理の画面.md 3.3)。
 * レコード・項目・規則・申請の削除で同じ部品を使い、確かめの形をそろえる。
 */
export function ConfirmDeleteDialog(props: Props) {
    const [innerOpen, setInnerOpen] = useState(false);
    const open = props.open ?? innerOpen;
    const setOpen = props.onOpenChange ?? setInnerOpen;
    const [processing, setProcessing] = useState(false);

    const remove = () =>
        router.delete(props.action.url, {
            data: props.data,
            preserveScroll: props.preserveScroll ?? false,
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
            {props.open === undefined && (
                <DeleteTrigger
                    triggerText={props.triggerText}
                    triggerLabel={props.triggerLabel}
                    triggerVariant={props.triggerVariant}
                />
            )}
            <DialogContent onCloseAutoFocus={focusBackTo(props.returnFocusTo)}>
                <DialogHeader>
                    <DialogTitle>{props.title}</DialogTitle>
                    <DialogDescription>{props.description}</DialogDescription>
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
