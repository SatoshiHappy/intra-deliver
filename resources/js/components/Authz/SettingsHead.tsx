import { Fragment } from 'react';
import { GapHead, GridHead } from '@/components/Authz/GridCells';
import { SubjectHeadCell } from '@/components/Authz/SubjectHeadCell';
import { TableRow } from '@/components/ui/table';
import type { ColumnGroup } from '@/lib/Authz/gridColumns';
import { showsActionRow } from '@/lib/Authz/gridView';
import { actionLabel } from '@/lib/Authz/labels';

/**
 * 同じ束(カテゴリ)の列の数(対象者 × アクションと、対象者の間の空き)。
 */
function bundleSpan(group: ColumnGroup, actionCount: number): number {
    return group.subjects.length * (actionCount + 1) - 1;
}

/**
 * 束の間隔の種類(先頭の束の左 = edge、そのほかの束の左 = between)。
 */
function bundleGap(index: number) {
    return index === 0 ? 'edge' : 'between';
}

/**
 * 「設定」タブの見出し。段 1 = カテゴリ、段 2 = 対象者(部分下線と札「該当」)、段 3 = アクション(2 つ以上のときだけ)。
 */
export function SettingsHead({
    groups,
    actions,
    canDeleteColumn,
}: {
    groups: ColumnGroup[];
    actions: string[];
    canDeleteColumn: boolean;
}) {
    const showActions = showsActionRow(actions);

    return (
        <>
            <TableRow>
                <GridHead
                    rowSpan={showActions ? 3 : 2}
                    className="sticky left-0 z-30 min-w-64 border-b align-bottom"
                >
                    リソース
                </GridHead>
                {groups.map((group, index) => (
                    <Fragment key={group.category.code}>
                        <GapHead kind={bundleGap(index)} />
                        <GridHead
                            scope="colgroup"
                            colSpan={bundleSpan(group, actions.length)}
                            className="h-8 text-center text-xs font-normal text-muted-foreground"
                        >
                            {group.category.name ?? group.category.code}
                        </GridHead>
                    </Fragment>
                ))}
            </TableRow>
            <TableRow>
                {groups.map((group, index) => (
                    <Fragment key={group.category.code}>
                        <GapHead kind={bundleGap(index)} />
                        {group.subjects.map((subject, i) => (
                            <Fragment key={subject.id}>
                                {i > 0 && <GapHead kind="inner" />}
                                <SubjectHeadCell
                                    subject={subject}
                                    colSpan={actions.length}
                                    canDelete={canDeleteColumn}
                                />
                            </Fragment>
                        ))}
                    </Fragment>
                ))}
            </TableRow>
            {showActions && (
                <TableRow>
                    {groups.map((group, index) => (
                        <Fragment key={group.category.code}>
                            <GapHead kind={bundleGap(index)} bordered />
                            {group.subjects.map((subject, i) => (
                                <Fragment key={subject.id}>
                                    {i > 0 && <GapHead kind="inner" bordered />}
                                    {actions.map((action) => (
                                        <GridHead
                                            key={action}
                                            scope="col"
                                            band={subject.matches}
                                            className="h-8 border-b text-center text-xs font-normal text-muted-foreground"
                                        >
                                            {actionLabel(action)}
                                        </GridHead>
                                    ))}
                                </Fragment>
                            ))}
                        </Fragment>
                    ))}
                </TableRow>
            )}
        </>
    );
}
