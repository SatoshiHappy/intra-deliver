import { router, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut, UserRound } from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toastErrors } from '@/lib/flashToast';
import { nameOrCode } from '@/lib/nameOrCode';
import { currentDepartment, logout } from '@/routes/account';
import type { DepartmentCandidate, UserMenuSection } from '@/types';

const subTextClass = 'ml-auto pl-3 text-xs text-muted-foreground';

/**
 * 利用者メニュー(docs の 設計/デモユーザー切替/今の利用者.md 5.7)。押す前は 頭文字の丸 + 氏名 + カレント組織、
 * 開くと上から 所属(候補が 2 つ以上)→ 節(共有プロパティ userMenuSections)→ ログアウト を区切り線で分けて出す。
 * 名称が null のところはコードを出す(README の方針 9)。出すものが 1 つも無ければ開くメニューにせず、押せない帯を描く。
 */
export function UserMenu() {
    const { account, userMenuSections } = usePage().props;
    const { user, currentDepartment: current, departments } = account;
    const userLabel = user === null ? 'ゲスト' : nameOrCode(user);
    const departmentLabel = current === null ? null : nameOrCode(current);

    const groups: [string, ReactNode][] = [];

    if (departments.length >= 2) {
        groups.push([
            'departments',
            <DepartmentGroup
                departments={departments}
                currentId={current?.id ?? null}
            />,
        ]);
    }

    userMenuSections.forEach((section, index) => {
        if (section.items.length > 0) {
            groups.push([
                `section-${index}`,
                <SectionGroup section={section} />,
            ]);
        }
    });

    if (user !== null) {
        groups.push([
            'logout',
            <DropdownMenuItem
                className="cursor-pointer"
                onSelect={() =>
                    router.visit(logout(), { onError: toastErrors })
                }
            >
                <LogOut />
                ログアウト
            </DropdownMenuItem>,
        ]);
    }

    const badge = (
        <UserBadge
            initial={user === null ? null : Array.from(userLabel)[0]}
            userLabel={userLabel}
            departmentLabel={departmentLabel}
        />
    );

    // 出すものが無いとき(ゲストで、項目のある節も無いとき)は開くメニューにせず、押せない帯を描く(5.7)
    if (groups.length === 0) {
        return (
            <div className="ml-auto flex h-11 items-center gap-2.5 rounded-md pr-2.5 pl-1.5">
                {badge}
            </div>
        );
    }

    const triggerLabel =
        departmentLabel === null
            ? `利用者メニュー：${userLabel}`
            : `利用者メニュー：${userLabel}（${departmentLabel}）`;

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    aria-label={triggerLabel}
                    className="group ml-auto h-11 cursor-pointer gap-2.5 pr-2.5 pl-1.5 data-[state=open]:bg-accent"
                >
                    {badge}
                    <ChevronDown className="size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-74 rounded-xl p-1.5">
                {groups.map(([key, group], index) => (
                    <Fragment key={key}>
                        {index > 0 && <DropdownMenuSeparator />}
                        {group}
                    </Fragment>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * 押す前の表示の中身: 印(利用者は頭文字の丸、ゲストは人の印)と、氏名・カレント組織の 2 行。
 * 開くメニューの Trigger と、出すものが無いときの帯の両方で使う。
 */
function UserBadge({
    initial,
    userLabel,
    departmentLabel,
}: {
    initial: string | null;
    userLabel: string;
    departmentLabel: string | null;
}) {
    return (
        <>
            {initial === null ? (
                <span className="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground">
                    <UserRound className="size-[18px]" />
                </span>
            ) : (
                <Avatar className="size-[34px]">
                    <AvatarFallback className="bg-primary text-sm font-bold text-primary-foreground">
                        {initial}
                    </AvatarFallback>
                </Avatar>
            )}
            <span className="flex min-w-0 flex-col items-start leading-tight">
                <span className="text-sm font-medium">{userLabel}</span>
                {departmentLabel !== null && (
                    <span className="text-xs text-muted-foreground">
                        {departmentLabel}
                    </span>
                )}
            </span>
        </>
    );
}

/**
 * 所属の選択(カレント組織の切替 = 5.5)。DropdownMenuRadioGroup と RadioItem(値は組織の ID の文字列)で、選択中に ✓、主所属に添え字。
 * Radix は選んでいる行を選び直しても値の変更を知らせるので、今のカレント組織と比べ、同じなら送らない(5.7)。
 */
function DepartmentGroup({
    departments,
    currentId,
}: {
    departments: DepartmentCandidate[];
    currentId: number | null;
}) {
    const currentValue = currentId === null ? '' : String(currentId);

    return (
        <>
            <DropdownMenuLabel className="text-xs text-muted-foreground">
                所属
            </DropdownMenuLabel>
            <DropdownMenuRadioGroup
                value={currentValue}
                onValueChange={(value) => {
                    if (value === currentValue) {
                        return;
                    }

                    router.visit(currentDepartment(), {
                        data: { department_id: Number(value) },
                        onError: toastErrors,
                    });
                }}
            >
                {departments.map((department) => (
                    <DropdownMenuRadioItem
                        key={department.id}
                        value={String(department.id)}
                        className="cursor-pointer data-[state=checked]:font-medium data-[state=checked]:text-primary"
                    >
                        <span>{nameOrCode(department)}</span>
                        {department.isMain && (
                            <span className={subTextClass}>主所属</span>
                        )}
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
        </>
    );
}

/**
 * ほかのモジュールが足した節 1 つ(見出しと項目)。送り先・方法・値はサーバーが作ったものをそのまま使う。
 */
function SectionGroup({ section }: { section: UserMenuSection }) {
    return (
        <>
            <DropdownMenuLabel className="text-xs text-muted-foreground">
                {section.heading}
            </DropdownMenuLabel>
            {section.items.map((item) => (
                <DropdownMenuItem
                    key={item.code}
                    className="cursor-pointer"
                    onSelect={() =>
                        router.visit(item.url, {
                            method: item.method,
                            data: item.data,
                            onError: toastErrors,
                        })
                    }
                >
                    <span>{nameOrCode(item)}</span>
                    {item.note !== null && (
                        <span className={subTextClass}>{item.note}</span>
                    )}
                </DropdownMenuItem>
            ))}
        </>
    );
}
