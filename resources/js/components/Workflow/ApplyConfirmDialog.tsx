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
import { NameSegmentsText } from '@/components/Workflow/PersonMenu';
import type { NameSegment } from '@/lib/Workflow/nameSegments';

/**
 * 申請・再申請の確かめのダイアログ(docs の 設計/ワークフロー/画面/申請と案件.md 3 節。モックの画面 2): 題「申請しますか？」(再申請は「再申請しますか？」)、
 * 本文 = 案件名と次の処理対象者(名前は札の「…」つき)、ボタン「キャンセル」「決定」。「決定」でダイアログを閉じて onConfirm を呼ぶ(誤りは申請の画面の欄に出す)。
 */
export function ApplyConfirmDialog({
    label,
    description,
    disabled,
    onConfirm,
}: {
    /** 開くボタンと操作の名前(「申請」「再申請」) */
    label: string;
    description: NameSegment[];
    /** 開くボタンを押せなくする(送信中・承認 1 の処理対象者が 0 人) */
    disabled: boolean;
    onConfirm: () => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" disabled={disabled}>
                    {label}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{`${label}しますか？`}</DialogTitle>
                    <DialogDescription className="wrap-anywhere">
                        <NameSegmentsText segments={description} />
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
                        onClick={() => {
                            setOpen(false);
                            onConfirm();
                        }}
                    >
                        決定
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
