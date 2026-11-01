import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 帯の行の寸法(Tailwind は静的な文字列だけ拾うので、足し算は定数に書く): 左の余白 px-2 + 開閉の印 size-4 + 隙間 gap-2 = 名前の左端 = 中身の字下げ pl-8。
 */
const BAR_PADDING = 'px-2';
const ICON_SIZE = 'size-4';
const BAR_GAP = 'gap-2';
const BODY_INDENT = 'pl-8';

type Props = {
    /** 中身の id(名前の id は `<bodyId>-name`、補足の id は `<bodyId>-note`) */
    bodyId: string;
    name: string;
    /** 名前の右の補足(無ければ出さない)。区画の名前にも入る(同じ名前の見出しのまとまりと明細の帯の区画を分ける) */
    note?: string;
    isOpen: boolean;
    onToggle: () => void;
};

/** 帯の行のボタン(開閉の印・名前・補足) */
function FoldButton({ bodyId, name, note, isOpen, onToggle }: Props) {
    return (
        <button
            type="button"
            aria-expanded={isOpen}
            aria-controls={bodyId}
            onClick={onToggle}
            className={cn(
                'flex w-full items-center py-2 text-left outline-none',
                BAR_GAP,
                'focus-visible:ring-2 focus-visible:ring-ring',
            )}
        >
            <ChevronDown
                aria-hidden="true"
                className={cn(
                    'shrink-0 text-muted-foreground transition-transform',
                    ICON_SIZE,
                    !isOpen && '-rotate-90',
                )}
            />
            <span
                id={`${bodyId}-name`}
                className="text-sm font-semibold wrap-anywhere"
            >
                {name}
            </span>
            {note !== undefined && (
                <span
                    id={`${bodyId}-note`}
                    className="text-xs font-normal text-muted-foreground"
                >
                    {note}
                </span>
            )}
        </button>
    );
}

/**
 * 畳める区画 1 つ(薄い帯の行と、畳める中身。畳んでも中身は消さず隠すだけ)。見出しのまとまりと明細の帯が共有する
 * (docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 2 節・README.md の 4 節)。
 * 行 = 開閉の印(chevron)・名前・補足(明細の帯の「<n> 件」。見出しのまとまりは名前だけ)。中身は名前の左端に揃えて字下げする。ボタンなので Enter・Space も使える(aria-expanded)。
 */
export function RecordFoldSection({
    children,
    ...props
}: Props & { children: ReactNode }) {
    return (
        <section
            aria-labelledby={
                props.note === undefined
                    ? `${props.bodyId}-name`
                    : `${props.bodyId}-name ${props.bodyId}-note`
            }
        >
            <h3 className={cn('rounded-md bg-muted/50', BAR_PADDING)}>
                <FoldButton {...props} />
            </h3>
            <div
                id={props.bodyId}
                hidden={!props.isOpen}
                className={cn('pt-4', BODY_INDENT)}
            >
                {children}
            </div>
        </section>
    );
}
