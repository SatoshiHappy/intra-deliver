import { ArrowUp, Check, Minus, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * 設定のアイコン(許可 = Check、禁止 = X。継承は同じ形を薄い色にして左上に上向きの矢印を添える)。読み上げは呼び出し側の文字で行う。
 */
export function SettingMark({
    effect,
    inherited,
}: {
    effect: 'permit' | 'deny';
    inherited: boolean;
}) {
    const Icon = effect === 'permit' ? Check : X;

    return (
        <span className="inline-flex items-start" aria-hidden="true">
            {inherited && (
                <ArrowUp className="-mt-0.5 -mr-px size-2.5 text-muted-foreground" />
            )}
            <Icon
                className={cn(
                    'size-4',
                    inherited
                        ? 'text-muted-foreground'
                        : effect === 'permit'
                          ? 'text-primary'
                          : 'text-destructive',
                )}
            />
        </span>
    );
}

/**
 * 設定なしのアイコン。
 */
export function NoSettingMark() {
    return (
        <Minus className="size-4 text-muted-foreground" aria-hidden="true" />
    );
}
