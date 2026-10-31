import { Fragment, useState } from 'react';
import type {
    ComponentProps,
    Dispatch,
    ReactNode,
    SetStateAction,
} from 'react';
import { FolderHoverPanel } from '@/layouts/FolderHoverPanel';
import { GlobalNavigationEntries } from '@/layouts/GlobalNavigationEntries';
import { FolderTriggerContent } from '@/layouts/FolderTriggerContent';
import { SidebarAppHeading } from '@/layouts/SidebarAppHeading';
import { MenuIcon } from '@/components/MenuIcon';
import { MenuItemLink } from '@/components/Menu/MenuItemLink';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverAnchor } from '@/components/ui/popover';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarSeparator,
    useSidebar,
} from '@/components/ui/sidebar';
import { useFolderHover } from '@/hooks/use-folder-hover';
import type { FolderHover } from '@/hooks/use-folder-hover';
import { useIsCurrentItem } from '@/hooks/Menu/use-is-current-item';
import {
    currentItemClass,
    flyoutContentClass,
    sidebarIconClass,
} from '@/lib/Menu/menuClasses';
import type { SidebarView } from '@/lib/Menu/appNavigationView';
import { folderContainsCurrent } from '@/lib/Menu/menuTree';
import type { SidebarSection } from '@/lib/Menu/sidebarSections';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { MenuFolderNode, MenuItemNode, MenuNode } from '@/types';

// 項目の高さ 44px・帯では 44px の正方形の中央に 20px のアイコン(docs の 設計/画面の意匠/README.md の寸法の表)
const menuButtonClass = cn(
    'h-11 group-data-[collapsible=icon]:size-11! group-data-[collapsible=icon]:p-3!',
    sidebarIconClass,
);

// 入れ子の行の高さ 40px
const subButtonClass = cn('h-10', sidebarIconClass);

type IsCurrentItem = (item: MenuItemNode) => boolean;

/**
 * グローバルナビ(今のアプリの見出しと区画、または今のアプリが無いときのアプリの一覧。lib/Menu/appNavigationView の sidebarView)を
 * サイドバーに描く(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節)。区画ごとに SidebarGroup(見出しの区画は h3、アプリの一覧は h2)、
 * アイテムはアイコンと表示名のリンク、フォルダは開閉できる入れ子(深さは制限しない)。畳んだ帯(広い幅で畳んだとき)では、
 * 直下のフォルダは、マウスを乗せると右のパネルに中身を出し(FolderHoverPanel)、押すと右に一覧(DropdownMenu。RailFolder)を開く。
 * 狭い幅のシートの中では、畳んでいてもフォルダは開閉できる入れ子のまま。
 * 広げたサイドバー(狭い幅でない)では、閉じたフォルダ(どの段でも)にマウスを乗せると中身を右のパネルに出す(FolderHoverPanel)。
 * 現在地の判定の関数はここで 1 回だけ作り、フォルダの初めの開き具合のために子へ渡す。
 * 帯で一覧を開いているフォルダ(1 つまで)の state もここで持つ(帯のどれかの一覧が開いている間は、帯のどのフォルダもパネルを出さないため)。
 */
export function GlobalNavigationTree({ view }: { view: SidebarView }) {
    const { state, isMobile } = useSidebar();
    const isRail = state === 'collapsed' && !isMobile;
    const isCurrentItem = useIsCurrentItem();
    // 帯で一覧を開いているフォルダのコード(帯でなくなったら閉じる。後で帯に戻ったとき、押さずに開かないように。
    // props に合わせて状態を直す React の作法 = 描画の中で自分の状態だけを変える)
    const [openRailFolder, setOpenRailFolder] = useState<string | null>(null);

    if (!isRail && openRailFolder !== null) {
        setOpenRailFolder(null);
    }

    return (
        <nav aria-label="グローバルナビ" className="flex flex-col gap-2">
            {view.app !== null && <SidebarAppHeading app={view.app} />}
            {view.sections.map((section, index) => (
                <Fragment key={section.key}>
                    {/* 区画の境の線。見出しなしの区画の頭は広げたときも出し、見出しの区画の頭は帯だけに出す(広げたときは見出しで分かる) */}
                    {index > 0 && (
                        <SidebarSeparator
                            className={
                                section.heading === null
                                    ? undefined
                                    : 'hidden group-data-[collapsible=icon]:block'
                            }
                        />
                    )}
                    <SectionGroup
                        section={section}
                        Heading={view.app === null ? 'h2' : 'h3'}
                        isRail={isRail}
                        canHover={state === 'expanded' && !isMobile}
                        isCurrentItem={isCurrentItem}
                        openRailFolder={openRailFolder}
                        onOpenRailFolderChange={setOpenRailFolder}
                    />
                </Fragment>
            ))}
        </nav>
    );
}

/** 区画 1 つ(SidebarGroup)。見出しがあれば SidebarGroupLabel を Heading(アプリの一覧は h2、今のアプリの区画は h3)で描く */
function SectionGroup({
    section,
    Heading,
    ...context
}: {
    section: SidebarSection;
    Heading: 'h2' | 'h3';
} & Omit<ComponentProps<typeof TopNode>, 'node'>) {
    return (
        <SidebarGroup>
            {section.heading !== null && (
                <SidebarGroupLabel asChild className="text-muted-foreground">
                    <Heading>{section.heading}</Heading>
                </SidebarGroupLabel>
            )}
            <SidebarMenu>
                {section.nodes.map((node) => (
                    <TopNode key={node.code} node={node} {...context} />
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}

/** グループの直下の 1 つ(アイテム、フォルダ、帯ではフォルダの飛び出し) */
function TopNode({
    node,
    isRail,
    canHover,
    isCurrentItem,
    openRailFolder,
    onOpenRailFolderChange,
}: {
    node: MenuNode;
    isRail: boolean;
    canHover: boolean;
    isCurrentItem: IsCurrentItem;
    openRailFolder: string | null;
    onOpenRailFolderChange: Dispatch<SetStateAction<string | null>>;
}) {
    const label = nameOrCode(node);

    if (node.type === 'item') {
        return (
            <SidebarMenuItem>
                <SidebarMenuButton
                    asChild
                    tooltip={label}
                    className={cn(menuButtonClass, currentItemClass)}
                >
                    <MenuItemLink item={node} />
                </SidebarMenuButton>
            </SidebarMenuItem>
        );
    }

    if (isRail) {
        return (
            <RailFolder
                folder={node}
                label={label}
                isMenuOpen={openRailFolder === node.code}
                isAnyMenuOpen={openRailFolder !== null}
                onMenuOpenChange={(open) =>
                    // 閉じる要求は、自分が開いているときだけ効かせる(別のフォルダを押して開いた直後に、前のフォルダの閉じる要求が来ても上書きしない)
                    onOpenRailFolderChange((current) =>
                        open
                            ? node.code
                            : current === node.code
                              ? null
                              : current,
                    )
                }
            />
        );
    }

    return (
        <FolderNode
            folder={node}
            depth={0}
            canHover={canHover}
            isCurrentItem={isCurrentItem}
        />
    );
}

/**
 * 畳んだ帯の直下のフォルダ。広げたサイドバーの閉じたフォルダと同じ 2 つの操作を持つ(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節)。
 * マウスを乗せると(300ms)右にパネル(FolderHoverPanel。広げたサイドバーと同じ部品・同じ中身。焦点を移さない)を出し、
 * 押す(クリック・タップ・Enter・Space・↓)と右に一覧(DropdownMenu)を開く。一覧は Radix の既定のまま(開くと一覧へフォーカスが移る・
 * もう一度押すか Escape で閉じる・閉じると引き金へ戻る)。パネルが出ているときに押すと、パネルが閉じて一覧が開く。
 * 帯のどれかの一覧が開いている間は、帯のどのフォルダもパネルを出さない(一覧とパネルを重ねない)。
 * 一覧の開閉は呼び手の state(isMenuOpen・onMenuOpenChange。開くのは帯で 1 つまで)、パネルの開閉は useFolderHover が持つ。
 * aria-expanded は一覧の開閉に追従する(パネルはマウスだけの補助で変えない)。ツールチップは付けない。
 */
function RailFolder({
    folder,
    label,
    isMenuOpen,
    isAnyMenuOpen,
    onMenuOpenChange,
}: {
    folder: MenuFolderNode;
    label: string;
    isMenuOpen: boolean;
    isAnyMenuOpen: boolean;
    onMenuOpenChange: (open: boolean) => void;
}) {
    const hover = useFolderHover(!isAnyMenuOpen);

    return (
        <SidebarMenuItem>
            <Popover
                open={hover.open}
                onOpenChange={(open) => {
                    if (!open) {
                        hover.close();
                    }
                }}
            >
                <DropdownMenu
                    modal={false}
                    open={isMenuOpen}
                    onOpenChange={(open) => {
                        onMenuOpenChange(open);
                        hover.close();
                    }}
                >
                    <PopoverAnchor asChild>
                        <DropdownMenuTrigger asChild>
                            <SidebarMenuButton
                                className={menuButtonClass}
                                {...hover.rowProps}
                            >
                                <MenuIcon name={folder.icon} />
                                <span>{label}</span>
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                    </PopoverAnchor>
                    <DropdownMenuContent
                        side="right"
                        align="start"
                        className={flyoutContentClass}
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            {label}
                        </DropdownMenuLabel>
                        <GlobalNavigationEntries nodes={folder.children} />
                    </DropdownMenuContent>
                </DropdownMenu>
                <FolderHoverPanel folder={folder} hover={hover} />
            </Popover>
        </SidebarMenuItem>
    );
}

/** フォルダの段の共通の入力(depth = 0 はグループの直下、それ以外はフォルダの中) */
type FolderProps = {
    folder: MenuFolderNode;
    depth: number;
    canHover: boolean;
    isCurrentItem: IsCurrentItem;
};

/**
 * フォルダ(どの段でも)。開閉の状態(初めは子孫に現在地があれば開く。覚えない)をここで持ち、マウスを乗せられる(広げたサイドバー)なら
 * パネルつきの HoverFolder、そうでなければ(狭い幅のシート)素の FolderShell で描く。乗せられるかが変わっても開閉の状態は残る。
 */
function FolderNode(props: FolderProps) {
    const [isExpanded, setIsExpanded] = useState(() =>
        folderContainsCurrent(props.folder, props.isCurrentItem),
    );

    return props.canHover ? (
        <HoverFolder
            {...props}
            isExpanded={isExpanded}
            onExpandedChange={setIsExpanded}
        />
    ) : (
        <FolderShell
            {...props}
            open={isExpanded}
            onOpenChange={setIsExpanded}
        />
    );
}

/**
 * 閉じたフォルダのマウスオーバーのパネルつきのフォルダ。パネルの開閉(useFolderHover)を持ち、引き金の行に PopoverAnchor を結ぶ。
 * フォルダを押して開閉したときとパネルのリンクを押したときにパネルを閉じる。
 */
function HoverFolder({
    isExpanded,
    onExpandedChange,
    ...props
}: FolderProps & {
    isExpanded: boolean;
    onExpandedChange: (open: boolean) => void;
}) {
    const hover = useFolderHover(!isExpanded);

    return (
        <Popover
            open={hover.open}
            onOpenChange={(open) => {
                if (!open) {
                    hover.close();
                }
            }}
        >
            <FolderShell
                {...props}
                open={isExpanded}
                onOpenChange={(open) => {
                    onExpandedChange(open);
                    hover.close();
                }}
                rowProps={hover.rowProps}
                wrapTrigger={(trigger) => (
                    <PopoverAnchor asChild>{trigger}</PopoverAnchor>
                )}
            />
            <FolderHoverPanel folder={props.folder} hover={hover} />
        </Popover>
    );
}

/**
 * フォルダの開閉の骨組み(Collapsible・引き金・中身。状態を持たない)。depth で、外側の li の部品(0 = SidebarMenuItem、それ以外 = SidebarMenuSubItem)、
 * Collapsible の asChild の有無、引き金の部品(0 = SidebarMenuButton、それ以外 = SidebarMenuSubButton > button)を切り替える。
 * rowProps は引き金の行に付けるポインタのハンドラ、wrapTrigger は引き金を包む部品(パネルの PopoverAnchor)。
 */
function FolderShell({
    folder,
    depth,
    canHover,
    isCurrentItem,
    open,
    onOpenChange,
    rowProps,
    wrapTrigger = (trigger) => trigger,
}: FolderProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    rowProps?: FolderHover['rowProps'];
    wrapTrigger?: (trigger: ReactNode) => ReactNode;
}) {
    const trigger = (
        <CollapsibleTrigger asChild>
            {depth === 0 ? (
                <SidebarMenuButton
                    className={cn(menuButtonClass, 'group/folder')}
                    {...rowProps}
                >
                    <FolderTriggerContent folder={folder} />
                </SidebarMenuButton>
            ) : (
                <SidebarMenuSubButton
                    asChild
                    className={cn(subButtonClass, 'group/folder w-full')}
                    {...rowProps}
                >
                    <button type="button">
                        <FolderTriggerContent folder={folder} />
                    </button>
                </SidebarMenuSubButton>
            )}
        </CollapsibleTrigger>
    );
    const body = (
        <>
            {wrapTrigger(trigger)}
            <CollapsibleContent>
                <SidebarMenuSub>
                    {folder.children.map((child) => (
                        <SubNode
                            key={child.code}
                            node={child}
                            depth={depth + 1}
                            canHover={canHover}
                            isCurrentItem={isCurrentItem}
                        />
                    ))}
                </SidebarMenuSub>
            </CollapsibleContent>
        </>
    );

    return depth === 0 ? (
        <Collapsible asChild open={open} onOpenChange={onOpenChange}>
            <SidebarMenuItem>{body}</SidebarMenuItem>
        </Collapsible>
    ) : (
        <SidebarMenuSubItem>
            <Collapsible open={open} onOpenChange={onOpenChange}>
                {body}
            </Collapsible>
        </SidebarMenuSubItem>
    );
}

/** フォルダの中の 1 つ(同じ部品を再帰で使う。引き金はどの段でも 1 つのボタン) */
function SubNode({
    node,
    depth,
    canHover,
    isCurrentItem,
}: {
    node: MenuNode;
    depth: number;
    canHover: boolean;
    isCurrentItem: IsCurrentItem;
}) {
    if (node.type === 'item') {
        return (
            <SidebarMenuSubItem>
                <SidebarMenuSubButton
                    asChild
                    className={cn(subButtonClass, currentItemClass)}
                >
                    <MenuItemLink item={node} />
                </SidebarMenuSubButton>
            </SidebarMenuSubItem>
        );
    }

    return (
        <FolderNode
            folder={node}
            depth={depth}
            canHover={canHover}
            isCurrentItem={isCurrentItem}
        />
    );
}
