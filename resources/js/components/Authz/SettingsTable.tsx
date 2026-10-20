import { Fragment, useMemo } from 'react';
import { GapCell } from '@/components/Authz/GridCells';
import { GridFrame } from '@/components/Authz/GridFrame';
import { GridRow } from '@/components/Authz/GridRow';
import { SettingCell } from '@/components/Authz/SettingCell';
import { SettingsHead } from '@/components/Authz/SettingsHead';
import type { TreeState } from '@/hooks/Authz/useGridView';
import {
    groupByCategory,
    indexSettings,
    settingKey,
} from '@/lib/Authz/gridColumns';
import type { ColumnGroup } from '@/lib/Authz/gridColumns';
import type { AuthzGrid, AuthzGridRow } from '@/types';

type Props = {
    grid: AuthzGrid;
    rows: AuthzGridRow[];
    tree: TreeState;
};

/**
 * 「設定」タブの 1 行の、先頭の列より右のセル(束 → 対象者 → アクションの順)。
 */
function SettingCells({
    row,
    groups,
    actions,
}: {
    row: AuthzGridRow;
    groups: ColumnGroup[];
    actions: string[];
}) {
    const settings = indexSettings(row.settings);

    return groups.map((group, index) => (
        <Fragment key={group.category.code}>
            <GapCell kind={index === 0 ? 'edge' : 'between'} />
            {group.subjects.map((subject, i) => (
                <Fragment key={subject.id}>
                    {i > 0 && <GapCell kind="inner" />}
                    {actions.map((action) => (
                        <SettingCell
                            key={action}
                            band={subject.matches}
                            setting={settings.get(
                                settingKey(subject.id, action),
                            )}
                        />
                    ))}
                </Fragment>
            ))}
        </Fragment>
    ));
}

/**
 * 「設定」タブの表(行 = リソースの木、列 = 対象者 × アクション)。
 */
export function SettingsTable({ grid, rows, tree }: Props) {
    const groups = useMemo(
        () => groupByCategory(grid.subjects),
        [grid.subjects],
    );

    return (
        <GridFrame
            head={<SettingsHead groups={groups} actions={grid.actions} />}
        >
            {rows.map((row) => (
                <GridRow
                    key={row.resourceGroupId}
                    row={row}
                    actions={grid.actions}
                    tree={tree}
                >
                    <SettingCells
                        row={row}
                        groups={groups}
                        actions={grid.actions}
                    />
                </GridRow>
            ))}
        </GridFrame>
    );
}
