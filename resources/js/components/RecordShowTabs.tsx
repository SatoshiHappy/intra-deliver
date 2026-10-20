import { ChevronDown } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode, Ref } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { fittingTabCount } from '@/lib/recordShow';
import { cn } from '@/lib/utils';

type RecordShowTab = {
    key: string;
    label: string;
    /** 札の件数(null = 出さない) */
    count: number | null;
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
                </span>
            ))}
            <span className={TAB}>
                その他
                <ChevronDown className="size-3.5" />
            </span>
        </div>
    );
}

/** 帯に入らなかったタブの「その他」のメニュー(選んだタブがあれば、ボタンの名前がそのタブの名前と件数になる) */
function MoreMenu({ tabs: hidden, selected, onSelect }: TabsProps) {
    const hiddenSelected = hidden.find((tab) => tab.key === selected);

    return (
        <DropdownMenu>
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
                    <DropdownMenuItem
                        key={tab.key}
                        onSelect={() => onSelect(tab.key)}
                    >
                        {tabName(tab)}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
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
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={tab.key === selected}
                        aria-label={tabName(tab)}
                        className={cn(TAB, tab.key === selected && SELECTED)}
                        onClick={() => onSelect(tab.key)}
                    >
                        <TabText tab={tab} selected={tab.key === selected} />
                    </button>
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
