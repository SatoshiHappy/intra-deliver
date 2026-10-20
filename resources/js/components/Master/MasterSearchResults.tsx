import { router } from '@inertiajs/react';
import {
    CommandEmpty,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import type { MasterSearchState } from '@/hooks/use-master-search';
import { nameOrCode } from '@/lib/nameOrCode';
import { organizationChart } from '@/routes/master';
import type { MasterSearchResult } from '@/types';

type Props = {
    search: MasterSearchState;
    /** 選んだときの後始末(欄を空にして閉じる)。容器が渡す */
    onSelected: () => void;
};

type SearchedUser = MasterSearchResult['users'][number];

/** 待ち・失敗の知らせ(CommandEmpty と同じ見た目) */
const noticeClass = 'py-6 text-center text-sm';

/**
 * ユーザーを選んだときの行き先: 主所属があればその組織を選んでその人を強調、無ければ強調だけ(頂点が選ばれる)。
 */
function userHref(user: SearchedUser) {
    return organizationChart.url({
        query:
            user.mainDepartment === null
                ? { user: user.id }
                : { department: user.mainDepartment.id, user: user.id },
    });
}

/**
 * 共通マスタの検索の結果(docs の 設計/共通マスタ/検索と組織図.md 3.2・3.4)。CommandList の中に、群「ユーザー」「組織」(0 件の群は出さない)・
 * 該当なし・最初の結果を待つ間の「検索中…」・失敗を描く。選ぶと容器の後始末を呼んでから組織図へ通常の移動をする。容器(Popover・Dialog)に依らない。
 * 項目の value は user:<id>・department:<id>(同じ表示の項目もキー操作で区別できるように)。
 */
export function MasterSearchResults({ search, onSelected }: Props) {
    const select = (href: string) => {
        onSelected();
        router.visit(href);
    };

    if (search.hasFailed) {
        return (
            <CommandList>
                <p className={noticeClass}>検索できませんでした</p>
            </CommandList>
        );
    }

    if (search.result === null) {
        return (
            <CommandList>
                {search.isWaiting && <p className={noticeClass}>検索中…</p>}
            </CommandList>
        );
    }

    const { users, departments } = search.result;

    return (
        <CommandList>
            {users.length === 0 && departments.length === 0 && (
                <CommandEmpty>該当するユーザー・組織はありません</CommandEmpty>
            )}
            {users.length > 0 && (
                <CommandGroup heading="ユーザー">
                    {users.map((user) => (
                        <CommandItem
                            key={user.id}
                            value={`user:${user.id}`}
                            onSelect={() => select(userHref(user))}
                        >
                            <span className="truncate">{nameOrCode(user)}</span>
                            {user.mainDepartment !== null && (
                                <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                    {nameOrCode(user.mainDepartment)}
                                </span>
                            )}
                        </CommandItem>
                    ))}
                </CommandGroup>
            )}
            {departments.length > 0 && (
                <CommandGroup heading="組織">
                    {departments.map((department) => (
                        <CommandItem
                            key={department.id}
                            value={`department:${department.id}`}
                            onSelect={() =>
                                select(
                                    organizationChart.url({
                                        query: { department: department.id },
                                    }),
                                )
                            }
                        >
                            <span className="truncate">
                                {nameOrCode(department)}
                            </span>
                        </CommandItem>
                    ))}
                </CommandGroup>
            )}
        </CommandList>
    );
}
