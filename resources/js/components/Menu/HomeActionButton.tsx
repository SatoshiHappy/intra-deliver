import { Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { toastErrors } from '@/lib/flashToast';
import type { HomeAction } from '@/types';

type Props = {
    action: HomeAction;
    /** 項目の中の操作のとき、読み上げ用に添える項目の名前(同じ語のボタンが並ぶため。`(<名前>)`) */
    ownerName?: string;
    variant?: 'default' | 'outline';
    size?: 'default' | 'sm';
};

/**
 * ホームの節の操作(HomeAction)を描く(docs の 設計/メニュー/ホーム.md 6.2)。送り先・方法・値はサーバーが作ったものをそのまま使う。
 * url が # で始まればページ内リンク(a)、method が get なら Inertia の Link、そうでなければ router.visit(失敗はトーストで出す。利用者メニューの項目と同じ)。
 */
export function HomeActionButton({
    action,
    ownerName,
    variant = 'default',
    size = 'default',
}: Props) {
    const content = (
        <>
            {action.label}
            {ownerName !== undefined && (
                <span className="sr-only">({ownerName})</span>
            )}
        </>
    );

    if (action.url.startsWith('#')) {
        return (
            <Button asChild variant={variant} size={size}>
                <a href={action.url}>{content}</a>
            </Button>
        );
    }

    if (action.method === 'get') {
        return (
            <Button asChild variant={variant} size={size}>
                <Link href={action.url} data={action.data}>
                    {content}
                </Link>
            </Button>
        );
    }

    return (
        <Button
            type="button"
            variant={variant}
            size={size}
            onClick={() =>
                router.visit(action.url, {
                    method: action.method,
                    data: action.data,
                    onError: toastErrors,
                })
            }
        >
            {content}
        </Button>
    );
}
