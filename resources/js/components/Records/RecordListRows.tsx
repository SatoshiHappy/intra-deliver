import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { RecordAddonBadges } from '@/components/Records/RecordAddonBadges';
import { RecordValue } from '@/components/RecordValue';
import { TableCell, TableRow } from '@/components/ui/table';
import { addonBadgeColumn, ownValue } from '@/lib/Records/recordAddons';
import {
    EMPTY_VALUE,
    formatDatetime,
    formatRecordValue,
} from '@/lib/recordValues';
import { show } from '@/routes/records/data';
import type {
    RecordAddonBadge,
    RecordFieldDefinition,
    RecordFieldValue,
    RecordList,
} from '@/types';

const LINK_CLASS = 'text-primary underline-offset-4 hover:underline';

/** セルの値と、その右の札(札が無ければ値だけ) */
function WithBadges({
    badges,
    children,
}: {
    badges: RecordAddonBadge[];
    children: ReactNode;
}) {
    return badges.length === 0 ? (
        children
    ) : (
        <span className="inline-flex flex-wrap items-center gap-2">
            {children}
            <RecordAddonBadges badges={badges} />
        </span>
    );
}

/** 一覧に出す項目のセルの値(表示の項目は詳細へのリンク) */
function ListedValue({
    field,
    value,
    href,
}: {
    field: RecordFieldDefinition;
    value: RecordFieldValue;
    href: ReturnType<typeof show>;
}) {
    return field.isTitle ? (
        <Link href={href} className={LINK_CLASS}>
            {formatRecordValue(field, value, 'cell')}
        </Link>
    ) : (
        <RecordValue field={field} value={value} place="cell" />
    );
}

/** 行の 1 つ(表示の項目のセルも詳細へのリンク)。badgeColumn = 札を出す列(null =「#」の列) */
function RecordListRow({
    moduleCode,
    listed,
    record,
    badges,
    badgeColumn,
}: {
    moduleCode: string;
    listed: RecordFieldDefinition[];
    record: RecordList['data'][number];
    badges: RecordAddonBadge[];
    badgeColumn: string | null;
}) {
    const href = show({ module: moduleCode, record: record.id });

    return (
        <TableRow>
            <TableCell>
                <WithBadges badges={badgeColumn === null ? badges : []}>
                    <Link
                        href={href}
                        className={`font-num tabular-nums ${LINK_CLASS}`}
                    >
                        {record.id}
                    </Link>
                </WithBadges>
            </TableCell>
            {listed.map((field) => (
                <TableCell key={field.code}>
                    <WithBadges
                        badges={badgeColumn === field.code ? badges : []}
                    >
                        <ListedValue
                            field={field}
                            value={record.values[field.code] ?? null}
                            href={href}
                        />
                    </WithBadges>
                </TableCell>
            ))}
            <TableCell className="font-num tabular-nums">
                {record.updatedAt === null
                    ? EMPTY_VALUE
                    : formatDatetime(record.updatedAt)}
            </TableCell>
        </TableRow>
    );
}

/**
 * 一覧の表の行(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.1・2.5)。ページの大きさの上限に収めるため、行の描画をページから分けた。
 */
export function RecordListRows({
    moduleCode,
    listed,
    records,
    addonBadges,
}: {
    moduleCode: string;
    listed: RecordFieldDefinition[];
    records: RecordList;
    addonBadges: Record<string, RecordAddonBadge[]>;
}) {
    const badgeColumn = addonBadgeColumn(listed);

    if (records.data.length === 0) {
        return (
            <TableRow>
                <TableCell
                    colSpan={listed.length + 2}
                    className="text-center text-muted-foreground"
                >
                    レコードはありません
                </TableCell>
            </TableRow>
        );
    }

    return records.data.map((record) => (
        <RecordListRow
            key={record.id}
            moduleCode={moduleCode}
            listed={listed}
            record={record}
            badges={ownValue(addonBadges, record.id) ?? []}
            badgeColumn={badgeColumn}
        />
    ));
}
