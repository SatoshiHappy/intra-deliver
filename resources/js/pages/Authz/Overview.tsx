import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { GridPanel } from '@/components/Authz/GridPanel';
import { Legend } from '@/components/Authz/Legend';
import { SelectionForm } from '@/components/Authz/SelectionForm';
import { Card, CardContent } from '@/components/ui/card';
import { useGridView } from '@/hooks/Authz/useGridView';
import type { GridTab } from '@/lib/Authz/gridView';
import { overview } from '@/routes/authz';
import type { AuthzGrid, AuthzJudge, AuthzJudgeUser } from '@/types';

type Props = {
    resourceTypes: string[];
    grid: AuthzGrid;
    candidates: AuthzJudgeUser[];
    judge: AuthzJudge;
};

/**
 * 認可設定(読み取り専用。docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.3)。行 = リソースの木、
 * 列 = 対象者 × アクションの格子を、1 つのリソースの種類ごとに「設定」「判定」のタブで見る。選択(リソースの種類・判定する人)はサーバーへの
 * 部分再読み込みで、タブ・検索の語・畳んだ行は画面の状態のまま残す。
 */
export default function Overview({
    resourceTypes,
    grid,
    candidates,
    judge,
}: Props) {
    const [tab, setTab] = useState<GridTab>('settings');
    const { query, setQuery, shown, tree } = useGridView(grid.rows);

    return (
        <>
            <Head title="認可設定" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">認可設定</h1>
                <Card>
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex items-start justify-between gap-6">
                            <SelectionForm
                                resourceTypes={resourceTypes}
                                grid={grid}
                                candidates={candidates}
                                judge={judge}
                            />
                            <Legend tab={tab} />
                        </div>
                        <GridPanel
                            grid={grid}
                            rows={shown}
                            tree={tree}
                            query={query}
                            onQueryChange={setQuery}
                            tab={tab}
                            onTabChange={setTab}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Overview.layout = {
    breadcrumbs: [
        {
            title: '認可設定',
            href: overview(),
        },
    ],
};
