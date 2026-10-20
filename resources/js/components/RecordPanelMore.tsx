import { Button } from '@/components/ui/button';

/**
 * Records の欄の「さらに表示」と下の 1 行(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 2.1 節)。
 */
export function RecordPanelMore({
    loading,
    onMore,
}: {
    loading: boolean;
    onMore: () => void;
}) {
    return (
        <div className="grid justify-items-center gap-1.5 pt-3">
            <Button
                variant="outline"
                size="sm"
                disabled={loading}
                onClick={onMore}
            >
                さらに表示
            </Button>
            <p className="text-xs text-muted-foreground">
                5 件ずつ表示します。
            </p>
        </div>
    );
}
