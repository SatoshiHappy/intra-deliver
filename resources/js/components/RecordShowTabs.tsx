import type { ReactNode } from 'react';
import { RecordShowMenuTab } from '@/components/RecordShowMenuTab';
import { cn } from '@/lib/utils';

type RecordShowTab = {
    key: string;
    label: string;
    /** 札の件数(null = 出さない) */
    count: number | null;
    /** ポインタを合わせる・下矢印で出すメニューの中身(関連レコードの入口 = 関連レコード.md の 3 節) */
    menu?: ReactNode;
};

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

type TabsProps = {
    tabs: RecordShowTab[];
    selected: string;
    onSelect: (key: string) => void;
};

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
    return (
        <div className="border-b">
            <div role="tablist" className="flex min-w-0 items-end gap-1">
                {tabs.map((tab) => (
                    <BarTab
                        key={tab.key}
                        tab={tab}
                        selected={tab.key === selected}
                        onSelect={onSelect}
                    />
                ))}
            </div>
        </div>
    );
}

/**
 * 詳細の左のタブ(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 4 節): 全部を帯に並べる(多くて 4 つ。あふれた
 * ときの形は置かない)。選んだタブの中身は children。選び方(URL のクエリへの映し)は呼び手が持つ。
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
