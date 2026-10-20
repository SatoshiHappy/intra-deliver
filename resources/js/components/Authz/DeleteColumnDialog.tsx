import { UnshownErrors } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useDeleteColumn } from '@/hooks/Authz/useDeleteColumn';
import { subjectLabel } from '@/lib/Authz/gridColumns';
import type { AuthzGridSubject } from '@/types';

type Props = {
    subject: AuthzGridSubject;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * 列を削除する確かめのダイアログ(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.2)。件数は列の props のもので、
 * 違いを知らせる 422 の後に読み直した件数を出し直す。誤りはダイアログの中に出し、開いたままにする。
 */
export function DeleteColumnDialog({ subject, open, onOpenChange }: Props) {
    const del = useDeleteColumn(subject, () => onOpenChange(false));

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);

                if (next) {
                    del.clearErrors();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>列の削除</DialogTitle>
                    <DialogDescription className="flex flex-col gap-1">
                        <span>
                            列「{subjectLabel(subject)}」を削除しますか。
                        </span>
                        <span>
                            この列に設定された {subject.policyCount}{' '}
                            件の許可と禁止が、すべてのリソースの種類から削除されます。この操作は取り消せません。
                        </span>
                    </DialogDescription>
                </DialogHeader>
                <UnshownErrors errors={del.errors} shownKeys={[]} />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        キャンセル
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={del.processing || !del.canRemove}
                        onClick={del.remove}
                    >
                        削除
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
