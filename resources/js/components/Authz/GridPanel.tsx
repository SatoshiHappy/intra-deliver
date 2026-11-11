import { AddColumnDialog } from '@/components/Authz/AddColumnDialog';
import { GridToolbar } from '@/components/Authz/GridToolbar';
import { JudgementsTable } from '@/components/Authz/JudgementsTable';
import { SettingsTab } from '@/components/Authz/SettingsTab';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import type { CellEditor } from '@/hooks/Authz/useCellEdit';
import type { TreeState } from '@/hooks/Authz/useGridView';
import type { GridTab } from '@/lib/Authz/gridView';
import type { AuthzCan, AuthzGrid, AuthzGridRow } from '@/types';

type Props = {
    grid: AuthzGrid;
    /** 表に出す行(表示の範囲・検索・畳んだ行を反映したもの) */
    rows: AuthzGridRow[];
    tree: TreeState;
    query: string;
    onQueryChange: (query: string) => void;
    tab: GridTab;
    onTabChange: (tab: GridTab) => void;
    /** 今出す編集の操作(編集中でなければすべて偽) */
    operations: AuthzCan;
    /** セルを押す口(編集中でセルを押せるときだけ) */
    editor: CellEditor | null;
};

/**
 * 表の代わりに出す文(種類に行が無い・出す行が無い)。表を出すときは null。
 */
function emptyMessage(grid: AuthzGrid, rows: AuthzGridRow[]): string | null {
    if (grid.rows.length === 0) {
        return 'この種類の認可対象はありません';
    }

    return rows.length === 0 ? '検索条件に一致する認可対象はありません' : null;
}

/**
 * 表の本体: タブの行(左 = 札「設定」「判定」、右端 = 検索)と、選んだタブの表(行が無ければ代わりの文)と、表の下の右寄せの「列を追加」
 * (編集中で addSubject が真のとき。一覧の下の追加のボタンは右寄せ = docs の 設計/画面の意匠/README.md の採った方針 13)。
 * 札の切り替えは画面の中だけ(URL・localStorage に残さない)。編集中の案内は「設定」タブの表の上に出す。
 */
export function GridPanel({
    grid,
    rows,
    tree,
    query,
    onQueryChange,
    tab,
    onTabChange,
    operations,
    editor,
}: Props) {
    const empty = emptyMessage(grid, rows);

    return (
        <Tabs
            value={tab}
            onValueChange={(value) => onTabChange(value as GridTab)}
            className="gap-4"
        >
            <GridToolbar query={query} onQueryChange={onQueryChange} />
            {empty === null ? (
                <>
                    <SettingsTab
                        grid={grid}
                        rows={rows}
                        tree={tree}
                        operations={operations}
                        editor={editor}
                    />
                    <TabsContent value="judgements">
                        <JudgementsTable grid={grid} rows={rows} tree={tree} />
                    </TabsContent>
                </>
            ) : (
                <p className="py-8 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            )}
            {operations.addSubject && (
                <div className="flex justify-end">
                    <AddColumnDialog />
                </div>
            )}
        </Tabs>
    );
}
