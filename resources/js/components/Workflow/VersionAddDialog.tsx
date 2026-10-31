import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { VersionAddForm } from '@/components/Workflow/VersionAddForm';
import type { WorkflowFlowVersionRow } from '@/types';

/**
 * 「版を追加」(主ボタン)と、押すと開く「版を追加する」ダイアログ(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.2 節・4 節)。
 * 閉じると中身が外れて入力は初期値に戻る。成功したら閉じる(足した版を選んだ版のタブへ移るのはサーバーのリダイレクト)。
 * 今日はサーバーの今日(props の today。今日の版の判定と同じ時計)。
 */
export function VersionAddDialog({
    flowCode,
    flowName,
    last,
    today,
}: {
    flowCode: string;
    flowName: string;
    last: WorkflowFlowVersionRow;
    /** サーバーの今日(YYYY-MM-DD) */
    today: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" aria-haspopup="dialog">
                    版を追加
                </Button>
            </DialogTrigger>
            <DialogContent>
                <VersionAddForm
                    flowCode={flowCode}
                    flowName={flowName}
                    lastNumber={last.number}
                    lastStartDate={last.startDate}
                    today={today}
                    onSucceeded={() => setOpen(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
