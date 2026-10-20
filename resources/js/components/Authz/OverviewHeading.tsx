import { Button } from '@/components/ui/button';

/**
 * 画面の見出しの行。編集を始められる人(canStart)には、右に「権限設定を開始する」/「権限設定を終了する」を出す
 * (誤操作を防ぐロックを外す・戻すだけ。画面の中の状態。docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 1 節)。
 */
export function OverviewHeading({
    canStart,
    editing,
    onToggle,
}: {
    canStart: boolean;
    editing: boolean;
    onToggle: () => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <h1 className="text-2xl font-semibold">認可設定</h1>
            {canStart && (
                <Button
                    type="button"
                    variant={editing ? 'outline' : 'default'}
                    onClick={onToggle}
                >
                    {editing ? '権限設定を終了する' : '権限設定を開始する'}
                </Button>
            )}
        </div>
    );
}
