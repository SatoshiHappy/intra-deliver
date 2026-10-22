import { ChevronDown } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode, Ref } from 'react';
import { RecordShowMenuTab } from '@/components/RecordShowMenuTab';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { fittingTabCount } from '@/lib/recordShow';
import { cn } from '@/lib/utils';

type RecordShowTab = {
    key: string;
    label: string;
    /** 札の件数(null = 出さない) */
    count: number | null;
    /** ポインタを合わせる・下矢印で出すメニューの中身(関連レコードの入口 = 関連レコード.md の 3 節。「その他」の中では行のサブメニュー) */
    menu?: ReactNode;
};

/** タブの間の隙間(gap-1。帯に入る数を測るときに足す) */
const GAP = 4;

const TAB =
    'inline-flex h-[42px] shrink-0 items-center gap-2 -mb-px px-4 ' +
    'border-b-2 border-transparent font-medium whitespace-nowrap text-muted-foreground';
const SELECTED = 'border-primary font-bold text-primary';

function tabName(tab: RecordShowTab): string {
    return tab.count === null ? tab.label : `${tab.label} ${tab.count}`;
}

function TabText({ tab, selected }: { tab: RecordShowTab; selected: boolean }) {
    return (
        <>
            {tab.label}
            {tab.count !== null && (
                <span
                    className={cn(
                        'min-w-[22px] rounded-full bg-muted px-1.5 text-center font-num text-xs leading-5 tabular-nums',
                        selected && 'bg-primary text-primary-foreground',
                    )}
                >
                    {tab.count}
                </span>
            )}
        </>
    );
}

/**
 * 帯に入る数を、描いた幅から決める(4 節のあふれ。固定の数にしない)。測る列は読み上げに出さない写しで、帯と写しの幅の変化で測り直す。
 */
function useFittingCount(tabCount: number) {
    const listRef = useRef<HTMLDivElement>(null);
    const measureRef = useRef<HTMLDivElement>(null);
    const [count, setCount] = useState(tabCount);

    useLayoutEffect(() => {
        const list = listRef.current;
        const measure = measureRef.current;

        if (list === null || measure === null) {
            return;
        }

        const update = () => {
            const items = Array.from(measure.children) as HTMLElement[];
            const more = items.pop()?.offsetWidth ?? 0;
            const widths = items.map((item) => item.offsetWidth + GAP);
            setCount(fittingTabCount(widths, list.clientWidth, more));
        };
        const observer = new ResizeObserver(update);
        observer.observe(list);
        observer.observe(measure);
        update();

        return () => observer.disconnect();
    }, []);

    return { listRef, measureRef, count: Math.min(count, tabCount) };
}

type TabsProps = {
    tabs: RecordShowTab[];
    selected: string;
    onSelect: (key: string) => void;
};

/** 帯に入る数を測る写し(読み上げに出さない) */
function MeasureRow({
    tabs,
    measureRef,
}: Pick<TabsProps, 'tabs'> & { measureRef: Ref<HTMLDivElement> }) {
    return (
        <div
            ref={measureRef}
            aria-hidden="true"
            className="invisible absolute flex gap-1"
        >
            {tabs.map((tab) => (
                <span key={tab.key} className={TAB}>
                    <TabText tab={tab} selected={false} />
                    {tab.menu !== undefined && (
                        <ChevronDown className="size-3.5" />
                    )}
                </span>
            ))}
            <span className={TAB}>
                その他
                <ChevronDown className="size-3.5" />
            </span>
        </div>
    );
}

/**
 * 「その他」のメニューの行(メニューを持つタブは、行のサブメニューにそのメニューを出す = 帯の中と同じ入口の切り替えと「新規」)。
 */
function MoreMenuItem({
    tab,
    onSelect,
}: {
    tab: RecordShowTab;
    onSelect: (key: string) => void;
}) {
    return tab.menu === undefined ? (
        <DropdownMenuItem onSelect={() => onSelect(tab.key)}>
            {tabName(tab)}
        </DropdownMenuItem>
    ) : (
        <DropdownMenuSub>
            <DropdownMenuSubTrigger>{tabName(tab)}</DropdownMenuSubTrigger>
            <DropdownMenuSubContent className="min-w-60">
                {tab.menu}
            </DropdownMenuSubContent>
        </DropdownMenuSub>
    );
}

/** 帯に入らなかったタブの「その他」のメニュー(選んだタブがあれば、ボタンの名前がそのタブの名前と件数になる) */
function MoreMenu({ tabs: hidden, selected, onSelect }: TabsProps) {
    const hiddenSelected = hidden.find((tab) => tab.key === selected);

    // サブメニューの「新規」から開くダイアログが閉じた後も操作を受けられるよう、ほかのメニューと同じく modal にしない
    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger
                className={cn(TAB, hiddenSelected !== undefined && SELECTED)}
            >
                {hiddenSelected === undefined ? (
                    'その他'
                ) : (
                    <TabText tab={hiddenSelected} selected />
                )}
                <ChevronDown className="size-3.5" aria-hidden="true" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {hidden.map((tab) => (
                    <MoreMenuItem key={tab.key} tab={tab} onSelect={onSelect} />
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** 帯のタブ 1 つ(メニューを持つタブは、ポインタを合わせる・下矢印でメニューを出す) */
function BarTab({
    tab,
    selected,
    onSelect,
}: {
    tab: RecordShowTab;
    selected: boolean;
    onSelect: (key: string) => void;
}) {
    const className = cn(TAB, selected && SELECTED);
    const text = <TabText tab={tab} selected={selected} />;

    return tab.menu === undefined ? (
        <button
            type="button"
            role="tab"
            aria-selected={selected}
            aria-label={tabName(tab)}
            className={className}
            onClick={() => onSelect(tab.key)}
        >
            {text}
        </button>
    ) : (
        <RecordShowMenuTab
            label={tabName(tab)}
            selected={selected}
            className={className}
            onSelect={() => onSelect(tab.key)}
            menu={tab.menu}
        >
            {text}
        </RecordShowMenuTab>
    );
}

function TabBar({ tabs, selected, onSelect }: TabsProps) {
    const { listRef, measureRef, count } = useFittingCount(tabs.length);
    const hidden = tabs.slice(count);

    return (
        <div className="relative border-b">
            <MeasureRow tabs={tabs} measureRef={measureRef} />
            <div
                ref={listRef}
                role="tablist"
                className="flex min-w-0 items-end gap-1"
            >
                {tabs.slice(0, count).map((tab) => (
                    <BarTab
                        key={tab.key}
                        tab={tab}
                        selected={tab.key === selected}
                        onSelect={onSelect}
                    />
                ))}
                {hidden.length > 0 && (
                    <MoreMenu
                        tabs={hidden}
                        selected={selected}
                        onSelect={onSelect}
                    />
                )}
            </div>
        </div>
    );
}

/**
 * 詳細の左のタブ(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 4 節): 帯に入るだけ並べ、残りは右端の「その他」の
 * メニューから選ぶ(各行に件数)。選んだタブの中身は children。選び方(URL のクエリへの映し)は呼び手が持つ。
 */
export function RecordShowTabs({
    children,
    ...props
}: TabsProps & { children: ReactNode }) {
    const current = props.tabs.find((tab) => tab.key === props.selected);

    return (
        <div className="flex flex-col gap-4">
            <TabBar {...props} />
            <div
                role="tabpanel"
                aria-label={
                    current === undefined ? undefined : tabName(current)
                }
            >
                {children}
            </div>
        </div>
    );
}
