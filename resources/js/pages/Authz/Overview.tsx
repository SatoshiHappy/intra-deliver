import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { GridPanel } from '@/components/Authz/GridPanel';
import { Legend } from '@/components/Authz/Legend';
import { OverviewHeading } from '@/components/Authz/OverviewHeading';
import { SelectionForm } from '@/components/Authz/SelectionForm';
import { Card, CardContent } from '@/components/ui/card';
import { useEditMode } from '@/hooks/Authz/useEditMode';
import { useGridView } from '@/hooks/Authz/useGridView';
import type { GridTab } from '@/lib/Authz/gridView';
import { overview } from '@/routes/authz';
import type { AuthzCan, AuthzGrid, AuthzJudge, AuthzJudgeUser } from '@/types';

type Props = {
    resourceTypes: string[];
    grid: AuthzGrid;
    candidates: AuthzJudgeUser[];
    judge: AuthzJudge;
    can: AuthzCan;
};

/**
 * 認可設定(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.3)。行 = リソースの木、
 * 列 = 対象者 × アクションの格子を、1 つのリソースの種類ごとに「設定」「判定」のタブで見る。選択(リソースの種類・判定する人)はサーバーへの
 * 部分再読み込みで、タブ・検索の語・畳んだ行は画面の状態のまま残す。「権限設定を開始する」で編集中になり(can が真の操作だけを出す。
 * docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 1 節)、種類を替えても編集中のまま。
 */
export default function Overview({
    resourceTypes,
    grid,
    candidates,
    judge,
    can,
}: Props) {
    const [tab, setTab] = useState<GridTab>('settings');
    const { query, setQuery, shown, tree } = useGridView(grid.rows);
    const edit = useEditMode(can, grid.resourceType);

    return (
        <>
            <Head title="認可設定" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <OverviewHeading
                    canStart={edit.canStart}
                    editing={edit.editing}
                    onToggle={edit.toggle}
                />
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
                            operations={edit.operations}
                            editor={edit.editor}
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
