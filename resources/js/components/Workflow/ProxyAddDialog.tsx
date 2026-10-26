import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { ProxyForm } from '@/components/Workflow/ProxyForm';
import type { WorkflowProxyFlow } from '@/types';

/**
 * 「代理を追加」(主ボタン)と、押すと開く「代理を追加する」ダイアログ(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節)。
 * 閉じると中身が外れて入力は初期値に戻る。成功したら閉じる(トーストと表の更新はサーバーのリダイレクト)。
 */
export function ProxyAddDialog({
    isAdmin,
    flows,
    today,
}: {
    isAdmin: boolean;
    flows: WorkflowProxyFlow[];
    /** サーバーの今日(YYYY-MM-DD。開始日の既定値) */
    today: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" aria-haspopup="dialog">
                    代理を追加
                </Button>
            </DialogTrigger>
            <DialogContent className="flex max-h-[90vh] flex-col">
                <ProxyForm
                    isAdmin={isAdmin}
                    flows={flows}
                    today={today}
                    onSucceeded={() => setOpen(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
