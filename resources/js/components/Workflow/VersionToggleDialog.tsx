import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ComponentProps } from 'react';
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
import { disable, enable } from '@/routes/workflow/admin/versions';

/** 無効にする / 有効にするの文言(確かめのダイアログ) */
const TEXTS = {
    disable: {
        trigger: '無効にする',
        title: '版を無効にする',
        description: (number: number) =>
            `版 ${number} を無効にします。無効にした版の期間には申請できません。`,
    },
    enable: {
        trigger: '有効にする',
        title: '版を有効にする',
        description: (number: number) => `版 ${number} を有効にします。`,
    },
};

/**
 * 送信(成功は版のタブへ戻る。業務処理が拒んだときはダイアログを閉じて文言をトーストで出す)。
 */
function useToggle(flowCode: string, number: number, isDisabled: boolean) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const route = isDisabled ? enable : disable;

    const send = () =>
        router.post(
            route({ flow: flowCode, version: number }).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setOpen(false),
                onError: (errors) => {
                    setOpen(false);
                    toastErrors(errors);
                },
            },
        );

    return { open, setOpen, processing, send };
}

/**
 * 表の行のボタン(DialogTrigger の asChild が属性と ref を足すので、残りは Button に渡す。読み上げの名前に版の番号を含める)。
 */
function TriggerButton({
    number,
    text,
    ...props
}: ComponentProps<typeof Button> & { number: number; text: string }) {
    return (
        <Button
            {...props}
            type="button"
            variant="ghost"
            size="sm"
            aria-haspopup="dialog"
            aria-label={`版 ${number} を${text}`}
        >
            {text}
        </Button>
    );
}

/**
 * 版を無効にする・有効にするの確かめのダイアログ(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.2 節)。
 * 「決定」で送り、成功は版のタブへ戻る(トーストはサーバーのフラッシュ)。業務処理が拒んだとき(既に同じ状態 = キー version)は
 * ダイアログを閉じて文言をトーストで出す。
 */
export function VersionToggleDialog({
    flowCode,
    number,
    isDisabled,
}: {
    flowCode: string;
    number: number;
    /** 今の状態(ユーザー無効なら「有効にする」、有効なら「無効にする」) */
    isDisabled: boolean;
}) {
    const toggle = useToggle(flowCode, number, isDisabled);
    const texts = isDisabled ? TEXTS.enable : TEXTS.disable;

    return (
        <Dialog open={toggle.open} onOpenChange={toggle.setOpen}>
            <DialogTrigger asChild>
                <TriggerButton number={number} text={texts.trigger} />
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{texts.title}</DialogTitle>
                    <DialogDescription>
                        {texts.description(number)}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            キャンセル
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        disabled={toggle.processing}
                        onClick={toggle.send}
                    >
                        決定
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
