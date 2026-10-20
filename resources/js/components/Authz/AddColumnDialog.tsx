import { Plus } from 'lucide-react';
import { AddColumnFields } from '@/components/Authz/AddColumnFields';
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
import { useAddColumn } from '@/hooks/Authz/useAddColumn';

/**
 * 「列を追加」(編集中だけ)のダイアログ(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1)。種類を選び、その候補を 1 つ選んで追加する。
 */
export function AddColumnDialog() {
    const add = useAddColumn();

    return (
        <Dialog open={add.open} onOpenChange={add.setOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline">
                    <Plus />
                    列を追加
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>列を追加</DialogTitle>
                    <DialogDescription>
                        判定の対象者を 1 つ指定して、列を追加します。
                    </DialogDescription>
                </DialogHeader>
                <AddColumnFields
                    kind={add.kind}
                    picked={add.picked}
                    errors={add.errors}
                    onKindChange={add.chooseKind}
                    onPick={add.pick}
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => add.setOpen(false)}
                    >
                        キャンセル
                    </Button>
                    <Button
                        type="button"
                        disabled={add.processing}
                        onClick={add.submit}
                    >
                        追加
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
