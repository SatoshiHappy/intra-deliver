import { Link } from '@inertiajs/react';
import { RecordApplyFlowDialog } from '@/components/Records/RecordApplyFlowDialog';
import { Button } from '@/components/ui/button';
import { recordApplyButtonOf } from '@/lib/Records/recordApply';
import type { RecordApplyEntry } from '@/types';

/**
 * 詳細の見出しの「申請する」。出し方の枝は {@link recordApplyButtonOf}(正本 = docs の
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 2 節)。
 */
export function RecordApplyButton({
    entries,
    held,
    heading,
}: {
    entries: RecordApplyEntry[];
    held: boolean;
    heading: string;
}) {
    const button = recordApplyButtonOf(entries, held);

    switch (button.kind) {
        case 'none':
            return null;
        case 'disabled':
            return (
                <Button variant="outline" disabled>
                    申請する
                </Button>
            );
        case 'link':
            return (
                <Button variant="outline" asChild>
                    <Link href={button.href}>申請する</Link>
                </Button>
            );
        case 'dialog':
            return (
                <RecordApplyFlowDialog
                    entries={button.entries}
                    heading={heading}
                />
            );
    }
}
