import { Check } from 'lucide-react';
import { DestinationLink } from '@/components/DestinationLink';
import { MenuIcon } from '@/components/MenuIcon';
import { nameOrCode } from '@/lib/nameOrCode';
import type { AppTile } from '@/lib/Menu/appNavigationView';
import { cn } from '@/lib/utils';

/** 右上の「現在のアプリ」の印(色以外でも今のアプリが分かるように) */
const MARK_CLASS = cn(
    'absolute top-1.5 right-1.5 grid size-[18px] place-items-center rounded-full',
    'bg-primary text-primary-foreground',
);

/**
 * ランチャーのパネルのアプリのタイル。今のアプリのタイルだけ枠・地を替えて「現在のアプリ」の印と読み上げ用の語を付ける。
 * 意匠は docs の 設計/画面の意匠/骨格とヘッダー.md 3 節の 7(タイル)。
 */
export function LauncherTile({ tile: { app, isCurrent } }: { tile: AppTile }) {
    const hasDescription = app.description !== null && app.description !== '';

    return (
        <DestinationLink
            destination={app.entry}
            aria-current={isCurrent ? 'true' : undefined}
            className={cn(
                'relative flex flex-col items-center gap-1 rounded-md border border-transparent',
                'px-2 pt-3.5 pb-3 text-center hover:bg-accent',
                isCurrent && 'border-primary bg-secondary hover:bg-secondary',
            )}
        >
            <span
                className={cn(
                    'mb-1 grid size-11 place-items-center rounded-xl bg-secondary',
                    isCurrent && 'bg-background',
                )}
            >
                <MenuIcon
                    name={app.icon}
                    className="size-[22px] text-primary"
                />
            </span>
            <span
                className={cn(
                    'text-[13px] font-medium',
                    isCurrent && 'font-bold text-primary',
                )}
            >
                {nameOrCode(app)}
                {isCurrent && <span className="sr-only">(現在のアプリ)</span>}
            </span>
            {hasDescription && (
                <span className="text-xs text-muted-foreground">
                    {app.description}
                </span>
            )}
            {isCurrent && (
                <span aria-hidden="true" className={MARK_CLASS}>
                    <Check className="size-3" />
                </span>
            )}
        </DestinationLink>
    );
}
