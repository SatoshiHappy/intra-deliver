import { RecordColumnHeads } from '@/components/RecordFieldBadges';
import { RecordValue } from '@/components/RecordValue';
import {
    Table,
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { RecordDetailRow, RecordFieldDefinition } from '@/types';

/**
 * 明細の項目の表(列 = 明細のモジュールの項目)。行が無ければ「明細はありません」。
 */
export function RecordDetailsTable({
    field,
    rows,
}: {
    field: RecordFieldDefinition;
    rows: RecordDetailRow[];
}) {
    const columns = field.childModule?.fields ?? [];

    return rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">明細はありません</p>
    ) : (
        <Table>
            <TableHeader>
                <TableRow>
                    <RecordColumnHeads columns={columns} />
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow key={row.id}>
                        {columns.map((column) => (
                            <TableCell
                                key={column.code}
                                className="wrap-anywhere whitespace-normal"
                            >
                                <RecordValue
                                    field={column}
                                    value={row.values[column.code] ?? null}
                                    place="cell"
                                />
                            </TableCell>
                        ))}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
