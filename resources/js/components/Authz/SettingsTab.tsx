import { SettingsTable } from '@/components/Authz/SettingsTable';
import { TabsContent } from '@/components/ui/tabs';
import type { CellEditor } from '@/hooks/Authz/useCellEdit';
import type { TreeState } from '@/hooks/Authz/useGridView';
import type { AuthzCan, AuthzGrid, AuthzGridRow } from '@/types';

type Props = {
    grid: AuthzGrid;
    rows: AuthzGridRow[];
    tree: TreeState;
    /** 今出す編集の操作(編集中でなければすべて偽) */
    operations: AuthzCan;
    /** セルを押す口(編集中でセルを押せるときだけ) */
    editor: CellEditor | null;
};

/**
 * 「設定」タブの中身。セルを押せる間(editCells)は、表の上に保存の案内を出す。
 */
export function SettingsTab({ grid, rows, tree, operations, editor }: Props) {
    return (
        <TabsContent value="settings" className="flex flex-col gap-4">
            {operations.editCells && (
                <p className="text-sm text-muted-foreground">
                    セルを押すと、その場で保存されます。
                </p>
            )}
            <SettingsTable
                grid={grid}
                rows={rows}
                tree={tree}
                editor={editor}
                canDeleteColumn={operations.deleteSubject}
            />
        </TabsContent>
    );
}
