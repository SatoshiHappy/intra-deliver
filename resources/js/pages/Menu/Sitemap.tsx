import { Head } from '@inertiajs/react';
import { MenuTree } from '@/components/Menu/MenuTree';
import { nameOrCode } from '@/lib/nameOrCode';
import { sitemap } from '@/routes/menu';
import type { MenuGroupNode } from '@/types';

type Props = {
    groups: MenuGroupNode[];
};

export default function Sitemap({ groups }: Props) {
    return (
        <>
            <Head title="サイトマップ" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">サイトマップ</h1>
                <div className="grid grid-cols-3 gap-6">
                    {groups.map((group) => (
                        <section
                            key={group.code}
                            className="flex flex-col gap-3 rounded-xl border bg-card p-5 text-card-foreground"
                        >
                            <h2 className="text-[15px] font-semibold">
                                {nameOrCode(group)}
                            </h2>
                            <MenuTree nodes={group.children} />
                        </section>
                    ))}
                </div>
            </div>
        </>
    );
}

Sitemap.layout = {
    breadcrumbs: [
        {
            title: 'サイトマップ',
            href: sitemap(),
        },
    ],
};
