import { useMemo } from 'react';
import { GridHead } from '@/components/Authz/GridCells';
import { GridFrame } from '@/components/Authz/GridFrame';
import { GridRow } from '@/components/Authz/GridRow';
import { JudgementCell } from '@/components/Authz/JudgementCell';
import { TableRow } from '@/components/ui/table';
import type { TreeState } from '@/hooks/Authz/useGridView';
import { subjectLabel } from '@/lib/Authz/gridColumns';
import { showsActionRow } from '@/lib/Authz/gridView';
import { actionLabel } from '@/lib/Authz/labels';
import type { AuthzGrid, AuthzGridRow } from '@/types';

type Props = {
    grid: AuthzGrid;
    rows: AuthzGridRow[];
    tree: TreeState;
};

/**
 * 「判定」タブの表(行 = 「設定」タブと同じ木、列 = アクション。アクションが 1 つなら見出しは「判定」)。
 */
export function JudgementsTable({ grid, rows, tree }: Props) {
    const subjectNames = useMemo(
        () =>
            new Map(
                grid.subjects.map((subject) => [
                    subject.id,
                    subjectLabel(subject),
                ]),
            ),
        [grid.subjects],
    );

    return (
        <GridFrame
            head={
                <TableRow>
                    <GridHead
                        scope="col"
                        className="sticky left-0 z-30 min-w-64 border-b align-bottom"
                    >
                        リソース
                    </GridHead>
                    {grid.actions.map((action) => (
                        <GridHead
                            key={action}
                            scope="col"
                            className="min-w-36 border-b text-center"
                        >
                            {showsActionRow(grid.actions)
                                ? actionLabel(action)
                                : '判定'}
                        </GridHead>
                    ))}
                </TableRow>
            }
        >
            {rows.map((row) => (
                <GridRow
                    key={row.resourceGroupId}
                    row={row}
                    actions={grid.actions}
                    tree={tree}
                >
                    {grid.actions.map((action) => (
                        <JudgementCell
                            key={action}
                            judgement={row.judgements?.find(
                                (judgement) => judgement.action === action,
                            )}
                            subjectNames={subjectNames}
                        />
                    ))}
                </GridRow>
            ))}
        </GridFrame>
    );
}
