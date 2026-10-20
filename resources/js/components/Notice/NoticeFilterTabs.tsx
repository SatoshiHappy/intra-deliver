import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { NoticeFilter } from '@/types';

type Props = {
    current: NoticeFilter;
    unreadCount: number;
    /** タブの URL(既定の値は付けない) */
    urlOf: (filter: NoticeFilter) => string;
    /** タブを変えたときに取り直す props */
    only: string[];
};

/**
 * 通知の一覧のタブ「すべて」「未読 {n}」(URL の filter。今のタブに aria-current)。
 */
export function NoticeFilterTabs({ current, unreadCount, urlOf, only }: Props) {
    const tabs: { filter: NoticeFilter; label: string }[] = [
        { filter: 'all', label: 'すべて' },
        { filter: 'unread', label: '未読' },
    ];

    return (
        <nav
            aria-label="通知のタブ"
            className="flex flex-wrap items-center gap-1"
        >
            {tabs.map(({ filter, label }) => (
                <Link
                    key={filter}
                    href={urlOf(filter)}
                    only={only}
                    preserveState
                    aria-current={filter === current ? 'page' : undefined}
                    className={cn(
                        'inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-medium',
                        'hover:bg-accent hover:text-accent-foreground',
                        filter === current &&
                            'bg-secondary text-secondary-foreground',
                    )}
                >
                    {label}
                    {filter === 'unread' && (
                        <span className="font-num tabular-nums">
                            {unreadCount}
                        </span>
                    )}
                </Link>
            ))}
        </nav>
    );
}
