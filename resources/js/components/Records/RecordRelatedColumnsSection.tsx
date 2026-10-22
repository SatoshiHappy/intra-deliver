import { RecordRelatedColumnsDialog } from '@/components/Records/RecordRelatedColumnsDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { MAX_RELATED_COLUMNS } from '@/lib/recordRelated';
import type { RecordRelatedColumnSetting } from '@/types';

/** 行の左(項目の名前・「参照の項目」の札・表示先) */
function SettingName({ setting }: { setting: RecordRelatedColumnSetting }) {
    return (
        <div className="flex min-w-48 flex-col gap-1">
            <span className="font-medium">{setting.label}</span>
            <span className="flex items-center gap-2 text-xs text-muted-foreground">
                <Badge variant="outline">参照の項目</Badge>
                {setting.referencedModule.name}の詳細に表示
            </span>
        </div>
    );
}

/**
 * 参照の項目 1 つの行(項目の名前・「参照の項目」の札・表示先・選んだ列か「未選択」・「列を選択」・「<n> / 5 列」)。
 */
function SettingRow({
    moduleCode,
    setting,
}: {
    moduleCode: string;
    setting: RecordRelatedColumnSetting;
}) {
    const count = setting.columns.length;

    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
            <SettingName setting={setting} />
            <div className="flex flex-1 flex-wrap items-center gap-1.5">
                {count === 0 ? (
                    <span className="text-sm text-muted-foreground">
                        未選択 一覧の列を使用します。
                    </span>
                ) : (
                    setting.columns.map((column) => (
                        <Badge key={column.code} variant="secondary">
                            {column.name}
                        </Badge>
                    ))
                )}
            </div>
            <div className="flex items-center gap-3">
                {count > 0 && (
                    <span className="font-num text-sm text-muted-foreground tabular-nums">
                        {count} / {MAX_RELATED_COLUMNS} 列
                    </span>
                )}
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
            </div>
        </li>
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
            <h2
                id="module-related-columns"
                className="text-[15px] font-semibold"
            >
                関連レコードの列
            </h2>
            <p className="text-sm text-muted-foreground">
                参照の項目ごとに、参照先のレコードの詳細の「関連レコード」のタブに表示する列を選択します。未選択の場合は、一覧の列を使用します。
            </p>
            <ul className="divide-y">
                {settings.map((setting) => (
                    <SettingRow
                        key={setting.entry}
                        moduleCode={moduleCode}
                        setting={setting}
                    />
                ))}
            </ul>
        </section>
    );
}
