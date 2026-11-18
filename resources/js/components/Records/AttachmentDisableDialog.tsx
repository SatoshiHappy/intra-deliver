import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * 添付ファイルの追加・削除の許可を外す前の確かめ(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 2 節)。
 * 閉じる操作はどれもキャンセルと同じにする(Escape や外のクリックで送らないため)。
 */
export function AttachmentDisableDialog({
    open,
    notices,
    processing,
    onCancel,
    onConfirm,
}: {
    open: boolean;
    notices: string[];
    processing: boolean;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onCancel()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        添付ファイルの追加・削除の許可の解除
                    </DialogTitle>
                    <DialogDescription asChild>
                        <div className="flex flex-col gap-2">
                            {notices.map((notice, index) => (
                                <p key={index}>{notice}</p>
                            ))}
                            <p>このまま更新しますか。</p>
                        </div>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onCancel}>
                        キャンセル
                    </Button>
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={onConfirm}
                    >
                        更新
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
