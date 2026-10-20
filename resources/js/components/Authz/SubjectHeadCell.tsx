import { ColumnMenu } from '@/components/Authz/ColumnMenu';
import { GridHead } from '@/components/Authz/GridCells';
import { Badge } from '@/components/ui/badge';
import { subjectLabel } from '@/lib/Authz/gridColumns';
import type { AuthzGridSubject } from '@/types';

/**
 * 「設定」タブの対象者の見出し(部分下線と札「該当」)。canDelete のとき、組み込みでない列に「…」(列を削除)を添える。
 */
export function SubjectHeadCell({
    subject,
    colSpan,
    canDelete,
}: {
    subject: AuthzGridSubject;
    colSpan: number;
    canDelete: boolean;
}) {
    return (
        <GridHead
            scope="col"
            colSpan={colSpan}
            band={subject.matches}
            className="h-9 border-b border-input pb-1.5 text-center align-bottom"
        >
            <span className="inline-flex items-center gap-1.5">
                {subjectLabel(subject)}
                {subject.matches && <Badge>該当</Badge>}
                {canDelete && !subject.builtin && (
                    <ColumnMenu subject={subject} />
                )}
            </span>
        </GridHead>
    );
}
