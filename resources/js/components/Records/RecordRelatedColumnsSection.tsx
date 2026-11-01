import { HeadingHelp } from '@/components/HeadingHelp';
import { RecordRelatedColumnsDialog } from '@/components/Records/RecordRelatedColumnsDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { MAX_RELATED_COLUMNS } from '@/lib/recordRelated';
import type { RecordRelatedColumnSetting } from '@/types';

/** 表示する列の欄(選んだ列の札と「<n> / 5 列」。未選択なら「未選択 一覧の列を使用します。」) */
function ColumnsCell({ setting }: { setting: RecordRelatedColumnSetting }) {
    const count = setting.columns.length;

    if (count === 0) {
        return (
            <span className="text-muted-foreground">
                未選択 一覧の列を使用します。
            </span>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {setting.columns.map((column) => (
                <Badge key={column.code} variant="secondary">
                    {column.name}
                </Badge>
            ))}
            <span className="ml-2 font-num text-muted-foreground tabular-nums">
                {count} / {MAX_RELATED_COLUMNS} 列
            </span>
        </div>
    );
}

/**
 * 参照の項目 1 つの行(参照の項目の名前・表示先・表示する列・「列を選択」)。
 */
function SettingRow({
    moduleCode,
    setting,
}: {
    moduleCode: string;
    setting: RecordRelatedColumnSetting;
}) {
    return (
        <TableRow>
            <TableCell>{setting.label}</TableCell>
            <TableCell>
                {setting.referencedModule.name}の詳細の「関連レコード」のタブ
            </TableCell>
            <TableCell className="whitespace-normal">
                <ColumnsCell setting={setting} />
            </TableCell>
            <TableCell className="text-right">
                <RecordRelatedColumnsDialog
                    moduleCode={moduleCode}
                    setting={setting}
                    trigger={
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label={`列を選択(${setting.label})`}
                        >
                            列を選択
                        </Button>
                    }
                />
            </TableCell>
        </TableRow>
    );
}

/**
 * 面の見出し(横の「?」に説明)。
 */
function SectionHeading() {
    return (
        <div className="flex items-center gap-1.5">
            <h2
                id="module-related-columns"
                className="text-[15px] font-semibold"
            >
                関連レコードの列
            </h2>
            <HeadingHelp label="関連レコードの列">
                参照の項目ごとに、参照先のレコードの詳細の「関連レコード」のタブに表示する列を選択します。未選択の場合は、一覧の列を使用します。
            </HeadingHelp>
        </div>
    );
}

/**
 * 管理の業務モジュールの定義の面「関連レコードの列」(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の
 * 4 節)。他モジュール参照の項目が無ければ(明細のモジュールも)面を出さない。
 */
export function RecordRelatedColumnsSection({
    moduleCode,
    settings,
}: {
    moduleCode: string;
    settings: RecordRelatedColumnSetting[];
}) {
    if (settings.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="module-related-columns"
            className="flex flex-col gap-2 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <SectionHeading />
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>参照の項目</TableHead>
                        <TableHead>表示先</TableHead>
                        <TableHead>表示する列</TableHead>
                        <TableHead>
                            <span className="sr-only">操作</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {settings.map((setting) => (
                        <SettingRow
                            key={setting.entry}
                            moduleCode={moduleCode}
                            setting={setting}
                        />
                    ))}
                </TableBody>
            </Table>
        </section>
    );
}
