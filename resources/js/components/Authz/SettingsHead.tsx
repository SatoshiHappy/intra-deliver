import { Fragment } from 'react';
import { GapHead, GridHead } from '@/components/Authz/GridCells';
import { SubjectHeadCell } from '@/components/Authz/SubjectHeadCell';
import { TableRow } from '@/components/ui/table';
import type { ColumnGroup } from '@/lib/Authz/gridColumns';
import { showsActionRow } from '@/lib/Authz/gridView';
import { actionLabel } from '@/lib/Authz/labels';

/**
 * 束の間隔の種類(先頭の束の左 = edge、そのほかの束の左 = between)。
 */
function bundleGap(index: number) {
    return index === 0 ? 'edge' : 'between';
}

/**
 * 「設定」タブの見出し。段 1 = 対象者(名前・種類・部分下線・札「該当」)、段 2 = アクション(2 つ以上のときだけ)。
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
                    rowSpan={showActions ? 2 : 1}
                    className="sticky left-0 z-30 min-w-64 border-b align-bottom"
                >
                    認可対象
                </GridHead>
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
