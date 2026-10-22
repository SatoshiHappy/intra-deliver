import { ChevronDown } from 'lucide-react';
import { useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/** メニューを閉じるまでの猶予(タブからメニューへポインタを動かす間に閉じないため) */
const MENU_CLOSE_DELAY = 150;

/**
 * ポインタを合わせている間と、下矢印で開いたメニューの開閉。下矢印で開いたときだけ、閉じた後にフォーカスをタブへ戻す
 * (ポインタで開いた・閉じたときはフォーカスを動かさない)。
 */
function useHoverMenu() {
    const [open, setOpenState] = useState(false);
    const timer = useRef<number | undefined>(undefined);
    const byArrow = useRef(false);
    const setOpen = (next: boolean) => {
        window.clearTimeout(timer.current);
        setOpenState(next);
    };
    const show = (arrow: boolean) => {
        byArrow.current = arrow;
        setOpen(true);
    };
    const hide = () => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () => setOpenState(false),
            MENU_CLOSE_DELAY,
        );
    };

    return { open, setOpen, show, hide, byArrow };
}

type Props = {
    /** 読み上げの名前(名前と件数) */
    label: string;
    selected: boolean;
    className: string;
    onSelect: () => void;
    /** メニューの中身(DropdownMenuItem の並び) */
    menu: ReactNode;
    /** タブの文字(名前と件数の札) */
    children: ReactNode;
};

/**
 * メニューの中身の面(ポインタが乗っている間は開いたまま。ポインタで開いたメニューは、閉じてもフォーカスを動かさない)。
 */
function MenuContent({
    hover,
    children,
}: {
    hover: ReturnType<typeof useHoverMenu>;
    children: ReactNode;
}) {
    return (
        <DropdownMenuContent
            align="start"
            className="min-w-60"
            onPointerEnter={() => hover.show(hover.byArrow.current)}
            onPointerLeave={hover.hide}
            onCloseAutoFocus={(event) => {
                if (!hover.byArrow.current) {
                    event.preventDefault();
                }
            }}
        >
            {children}
        </DropdownMenuContent>
    );
}

/**
 * メニューを持つ左のタブ(関連レコードの入口の切り替え = docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 3 節)。
 * 押す・Enter・Space はタブを選び(メニューの開閉にしない)、ポインタを合わせる・下矢印でメニューを出す。
 */
export function RecordShowMenuTab({
    label,
    selected,
    className,
    onSelect,
    menu,
    children,
}: Props) {
    const hover = useHoverMenu();
    const keyDown = (event: KeyboardEvent) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onSelect();
        } else if (event.key === 'ArrowDown') {
            hover.show(true);
        }
    };

    return (
        <DropdownMenu
            open={hover.open}
            onOpenChange={hover.setOpen}
            modal={false}
        >
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    role="tab"
                    aria-selected={selected}
                    aria-label={label}
                    className={className}
                    onPointerDown={(event) => event.preventDefault()}
                    onClick={onSelect}
                    onKeyDown={keyDown}
                    onPointerEnter={() => hover.show(false)}
                    onPointerLeave={hover.hide}
                >
                    {children}
                    <ChevronDown className="size-3.5" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <MenuContent hover={hover}>{menu}</MenuContent>
        </DropdownMenu>
    );
}
