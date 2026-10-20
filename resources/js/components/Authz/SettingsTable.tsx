import { Fragment, useMemo } from 'react';
import { GapCell } from '@/components/Authz/GridCells';
import { GridFrame } from '@/components/Authz/GridFrame';
import { GridRow } from '@/components/Authz/GridRow';
import { SettingCell } from '@/components/Authz/SettingCell';
import type { CellPress } from '@/components/Authz/SettingCell';
import { SettingsHead } from '@/components/Authz/SettingsHead';
import type { CellEditor } from '@/hooks/Authz/useCellEdit';
import type { TreeState } from '@/hooks/Authz/useGridView';
import type { CellTarget } from '@/lib/Authz/cellEdit';
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
    /** 編集中でセルを押せるときだけ(押せる形のセルにする) */
    editor: CellEditor | null;
    /** 列の見出しの「…」(列を削除)を出すか */
    canDeleteColumn: boolean;
};

/**
 * 押せる形のセルの口(編集中でなければ null)。
 */
function pressFor(
    editor: CellEditor | null,
    target: CellTarget,
): CellPress | null {
    return editor === null
        ? null
        : {
              onPress: () => editor.press(target),
              saving: editor.isSaving(target),
          };
}

/**
 * 「設定」タブの 1 行の、先頭の列より右のセル(束 → 対象者 → アクションの順)。
 */
function SettingCells({
    row,
    groups,
    actions,
    editor,
}: {
    row: AuthzGridRow;
    groups: ColumnGroup[];
    actions: string[];
    editor: CellEditor | null;
}) {
    const settings = indexSettings(row.settings);

    return groups.map((group, index) => (
        <Fragment key={group.category.code}>
            <GapCell kind={index === 0 ? 'edge' : 'between'} />
            {group.subjects.map((subject, i) => (
                <Fragment key={subject.id}>
                    {i > 0 && <GapCell kind="inner" />}
                    {actions.map((action) => {
                        const setting = settings.get(
                            settingKey(subject.id, action),
                        );

                        return (
                            <SettingCell
                                key={action}
                                band={subject.matches}
                                setting={setting}
                                press={pressFor(editor, {
                                    resourceGroupId: row.resourceGroupId,
                                    subjectGroupId: subject.id,
                                    action,
                                    setting,
                                })}
                            />
                        );
                    })}
                </Fragment>
            ))}
        </Fragment>
    ));
}

/**
 * 「設定」タブの表(行 = リソースの木、列 = 対象者 × アクション)。
 */
export function SettingsTable({
    grid,
    rows,
    tree,
    editor,
    canDeleteColumn,
}: Props) {
    const groups = useMemo(
        () => groupByCategory(grid.subjects),
        [grid.subjects],
    );

    return (
        <GridFrame
            head={
                <SettingsHead
                    groups={groups}
                    actions={grid.actions}
                    canDeleteColumn={canDeleteColumn}
                />
            }
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
                        editor={editor}
                    />
                </GridRow>
            ))}
        </GridFrame>
    );
}
