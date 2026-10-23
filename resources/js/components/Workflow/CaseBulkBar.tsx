import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { BulkActionDialog } from '@/components/Workflow/BulkActionDialog';
import type { BulkTarget } from '@/components/Workflow/BulkActionDialog';
import { BULK_APPROVE_RULE, BULK_TEXTS } from '@/lib/Workflow/bulkActions';
import type { BulkAction } from '@/lib/Workflow/bulkActions';
import type { WorkflowCaseRow } from '@/types';

/**
 * 表の上の帯(「{N} 件選択中」と主ボタン「選択した {N} 件を承認」(未確認は「確認」)。0 件のときは押せない = docs の
 * 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節)と、押すと開く一括のダイアログ。開いた時の選んだ行を写して渡す。
 * 送って成功したら閉じて onDone(選択を外す)。
 */
export function CaseBulkBar({
    action,
    selected,
    commentMaxLength,
    onDone,
}: {
    action: BulkAction;
    selected: WorkflowCaseRow[];
    commentMaxLength: number;
    onDone: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState<BulkTarget | null>(null);
    const [openCount, setOpenCount] = useState(0);
    const count = selected.length;

    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-sm text-muted-foreground">
                <span className="font-num tabular-nums">{count}</span> 件選択中
            </span>
            <Button
                type="button"
                aria-haspopup="dialog"
                disabled={count === 0}
                onClick={() => {
                    setTarget({ action, rows: selected });
                    setOpenCount((current) => current + 1);
                    setOpen(true);
                }}
            >
                {BULK_TEXTS[action].submitLabel(count)}
            </Button>
            <BulkActionDialog
                open={open}
                target={target}
                openCount={openCount}
                commentMaxLength={commentMaxLength}
                onClose={() => setOpen(false)}
                onSucceeded={() => {
                    setOpen(false);
                    onDone();
                }}
            />
        </div>
    );
}

/**
 * 未処理のタブの表の下の 1 行(一括承認できる申請の条件)。
 */
export function BulkApproveRule() {
    return <p className="text-sm text-muted-foreground">{BULK_APPROVE_RULE}</p>;
}
