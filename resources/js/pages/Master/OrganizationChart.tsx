import { Head, Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import { organizationChart } from '@/routes/master';
import type {
    NamedDepartment,
    OrganizationMember,
    OrganizationNode,
} from '@/types';

type Props = {
    tree: OrganizationNode | null;
    selectedDepartment: NamedDepartment | null;
    members: OrganizationMember[];
    highlightedUserId: number | null;
};

/** 組織を押したときに取り直す props(木 tree は取り直さない = docs の 設計/共通マスタ/検索と組織図.md 1.1) */
const SELECTION_PROPS = ['selectedDepartment', 'members', 'highlightedUserId'];

/** 選んだ組織と強調するユーザーの見た目(サイドバーの現在地と同じ = docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節) */
const selectedClass = 'bg-secondary font-bold text-secondary-foreground';

/**
 * 組織の木(全部開いた字下げの入れ子の一覧)。各組織は ?department=<id> へのリンクで、押すと右側だけを部分再読み込みで取り直す
 * (クエリ user は付けない = ほかの組織を押すと強調が消える)。
 */
function DepartmentTree({
    nodes,
    selectedId,
    className,
}: {
    nodes: OrganizationNode[];
    selectedId: number | null;
    className?: string;
}) {
    return (
        <ul className={cn('flex flex-col gap-0.5', className)}>
            {nodes.map((node) => {
                const isSelected = node.id === selectedId;

                return (
                    <li key={node.id} className="flex flex-col gap-0.5">
                        <Link
                            href={organizationChart({
                                query: { department: node.id },
                            })}
                            only={SELECTION_PROPS}
                            preserveState
                            preserveScroll
                            aria-current={isSelected ? 'true' : undefined}
                            className={cn(
                                'rounded-md px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground',
                                isSelected && selectedClass,
                            )}
                        >
                            {nameOrCode(node)}
                        </Link>
                        {node.children.length > 0 && (
                            <DepartmentTree
                                nodes={node.children}
                                selectedId={selectedId}
                                className="pl-4"
                            />
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * 選んだ組織の所属ユーザーの表(列 = 氏名・役職・区分)。highlightedUserId と同じユーザーの行を強調する。
 */
function MemberTable({
    members,
    highlightedUserId,
}: {
    members: OrganizationMember[];
    highlightedUserId: number | null;
}) {
    if (members.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                所属しているユーザーはいません
            </p>
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>氏名</TableHead>
                    <TableHead>役職</TableHead>
                    <TableHead>区分</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {members.map((member) => {
                    const isHighlighted = member.id === highlightedUserId;

                    return (
                        // 同じ人の同じ組織への所属は期間が重ならない(DB の排他制約)ので、基準日の所属は 1 人 1 行
                        <TableRow
                            key={member.id}
                            aria-current={isHighlighted ? 'true' : undefined}
                            className={cn(isHighlighted && selectedClass)}
                        >
                            <TableCell>{nameOrCode(member)}</TableCell>
                            <TableCell>
                                {member.posts.map(nameOrCode).join('、')}
                            </TableCell>
                            <TableCell>
                                {member.isMain ? '主所属' : '兼務'}
                            </TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </Table>
    );
}

export default function OrganizationChart({
    tree,
    selectedDepartment,
    members,
    highlightedUserId,
}: Props) {
    return (
        <>
            <Head title="組織図" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">組織図</h1>
                <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] items-start gap-6">
                    <nav
                        aria-label="組織の木"
                        className="max-h-[calc(100svh-var(--header-height)-8rem)] overflow-y-auto rounded-xl border bg-card p-5 text-card-foreground"
                    >
                        {tree === null ? (
                            <p className="text-sm text-muted-foreground">
                                今日の組織図はありません
                            </p>
                        ) : (
                            <DepartmentTree
                                nodes={[tree]}
                                selectedId={selectedDepartment?.id ?? null}
                            />
                        )}
                    </nav>
                    {selectedDepartment !== null && (
                        <section
                            aria-labelledby="organization-chart-members"
                            className="flex flex-col gap-3 rounded-xl border bg-card p-5 text-card-foreground"
                        >
                            <h2
                                id="organization-chart-members"
                                className="text-[15px] font-semibold"
                            >
                                {nameOrCode(selectedDepartment)}
                            </h2>
                            <MemberTable
                                members={members}
                                highlightedUserId={highlightedUserId}
                            />
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

OrganizationChart.layout = {
    breadcrumbs: [
        {
            title: '組織図',
            href: organizationChart(),
        },
    ],
};
