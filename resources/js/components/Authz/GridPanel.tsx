import { JudgementsTable } from '@/components/Authz/JudgementsTable';
import { SettingsTable } from '@/components/Authz/SettingsTable';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { TreeState } from '@/hooks/Authz/useGridView';
import type { GridTab } from '@/lib/Authz/gridView';
import type { AuthzGrid, AuthzGridRow } from '@/types';

type Props = {
    grid: AuthzGrid;
    /** 表に出す行(検索・畳んだ行を反映したもの) */
    rows: AuthzGridRow[];
    tree: TreeState;
    query: string;
    onQueryChange: (query: string) => void;
    tab: GridTab;
    onTabChange: (tab: GridTab) => void;
};

/**
 * 表の本体: タブの行(左 = 札「設定」「判定」、右端 = 検索)と、選んだタブの表(行が無ければ代わりの文)。
 * 札の切り替えは画面の中だけ(URL・localStorage に残さない)。
 */
export function GridPanel({
    grid,
    rows,
    tree,
    query,
    onQueryChange,
    tab,
    onTabChange,
}: Props) {
    const empty =
        grid.rows.length === 0
            ? 'この種類のリソースはありません'
            : rows.length === 0
              ? '検索条件に一致するリソースはありません'
              : null;

    return (
        <Tabs
            value={tab}
            onValueChange={(value) => onTabChange(value as GridTab)}
            className="gap-4"
        >
            <div className="flex items-center justify-between gap-4">
                <TabsList
                    variant="line"
                    className="h-auto max-w-full flex-wrap justify-start"
                >
                    <TabsTrigger value="settings" className="flex-none">
                        設定
                    </TabsTrigger>
                    <TabsTrigger value="judgements" className="flex-none">
                        判定
                    </TabsTrigger>
                </TabsList>
                <Input
                    value={query}
                    onChange={(event) => onQueryChange(event.target.value)}
                    placeholder="リソース名で検索"
                    aria-label="リソース名で検索"
                    className="max-w-64"
                />
            </div>
            {empty === null ? (
                <>
                    <TabsContent value="settings">
                        <SettingsTable grid={grid} rows={rows} tree={tree} />
                    </TabsContent>
                    <TabsContent value="judgements">
                        <JudgementsTable grid={grid} rows={rows} tree={tree} />
                    </TabsContent>
                </>
            ) : (
                <p className="py-8 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            )}
        </Tabs>
    );
}
