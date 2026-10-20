import type { ReactNode } from 'react';
import { ResourceCell } from '@/components/Authz/ResourceCell';
import { TableRow } from '@/components/ui/table';
import type { TreeState } from '@/hooks/Authz/useGridView';
import { blockedActions } from '@/lib/Authz/gridView';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { AuthzGridRow } from '@/types';

type Props = {
    row: AuthzGridRow;
    actions: string[];
    tree: TreeState;
    /** 先頭の列より右のセル */
    children: ReactNode;
};

/**
 * 格子の 1 行(先頭の列 + 右のセル)。どれかのアクションが閉塞の行は、地を閉塞の状態色にする。
 */
export function GridRow({ row, actions, tree, children }: Props) {
    const blocked = blockedActions(row);
    const hasChildren = tree.parentIds.has(row.resourceGroupId);

    return (
        <TableRow
            className={cn(
                'group/row',
                blocked.length > 0
                    ? 'bg-status-rejected hover:bg-status-rejected has-aria-expanded:bg-status-rejected'
                    : 'has-aria-expanded:bg-transparent has-aria-expanded:hover:bg-muted/50',
            )}
        >
            <ResourceCell
                name={nameOrCode(row)}
                depth={row.depth}
                hasChildren={hasChildren}
                toggle={
                    hasChildren && !tree.isSearching
                        ? {
                              isOpen: tree.isOpen(row.resourceGroupId),
                              onToggle: () => tree.toggle(row.resourceGroupId),
                          }
                        : null
                }
                blocked={blocked}
                actions={actions}
            />
            {children}
        </TableRow>
    );
}
