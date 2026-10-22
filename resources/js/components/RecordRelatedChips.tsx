import type { RecordRelatedEntry } from '@/types';

const CHIP =
    'inline-flex items-center gap-1.5 rounded-full border bg-background ' +
    'px-2.5 py-0.5 text-xs hover:bg-muted';

/**
 * 詳細タブの先頭の関連レコードのチップ(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 4 節): 小さな題と、
 * 見える入口ごとの「<入口の名前> <件数>」(0 件も出す。幅に入らなければ折り返す)。押すと、その入口の関連レコードのタブを開く(onSelect)。
 * 見える入口が無ければ何も出さない。
 */
export function RecordRelatedChips({
    entries,
    onSelect,
}: {
    entries: RecordRelatedEntry[];
    onSelect: (key: string) => void;
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <div
            role="group"
            aria-label="関連レコードの件数"
            className="flex flex-wrap items-center gap-2"
        >
            <span className="text-xs font-medium text-muted-foreground">
                関連レコード
            </span>
            {entries.map((entry) => (
                <button
                    key={entry.key}
                    type="button"
                    aria-label={`${entry.name} ${entry.count} 件`}
                    onClick={() => onSelect(entry.key)}
                    className={CHIP}
                >
                    <span>{entry.name}</span>
                    <span className="font-num font-semibold tabular-nums">
                        {entry.count}
                    </span>
                </button>
            ))}
        </div>
    );
}
