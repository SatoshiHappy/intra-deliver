import { Ellipsis } from 'lucide-react';
import { useState } from 'react';
import { DeleteColumnDialog } from '@/components/Authz/DeleteColumnDialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { subjectLabel } from '@/lib/Authz/gridColumns';
import type { AuthzGridSubject } from '@/types';

/**
 * 列の見出しの「…」(編集中だけ。組み込みの 3 列には呼び手が出さない)。「列を削除」で確かめのダイアログを開く。
 * ダイアログはメニューの外に置く(メニューを閉じても残るように)。メニューは modal にしない(閉じたあとにダイアログが操作を受けられるように)。
 */
export function ColumnMenu({ subject }: { subject: AuthzGridSubject }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        aria-label={`${subjectLabel(subject)}の列の操作`}
                    >
                        <Ellipsis />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setOpen(true)}>
                        列を削除
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <DeleteColumnDialog
                subject={subject}
                open={open}
                onOpenChange={setOpen}
            />
        </>
    );
}
