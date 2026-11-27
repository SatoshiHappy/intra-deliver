import { useId } from 'react';
import type { ComponentProps, SyntheticEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type Common = Omit<
    ComponentProps<typeof Button>,
    'variant' | 'size' | 'disabled'
> & {
    /** 操作の名前(Tooltip に出す。aria-label を省くと読み上げの名前にもする) */
    label: string;
    /** 削除の形(赤) */
    destructive?: boolean;
};

/**
 * 押せる形と、押せない形(理由つき)。押せない形はリンク(asChild)にしない(中ボタンなどのクリックは onClick を通らず、止められないため)。
 */
type Props =
    | (Common & { disabledReason?: undefined })
    | (Omit<Common, 'asChild'> & {
          /** 押せない理由(押せない形にし、マウスを載せる・フォーカスすると名前と理由を出し、読み上げは説明に理由を読む) */
          disabledReason: string;
          asChild?: never;
      });

/**
 * 押せない間は既定の動きを止めて呼び手の処理を呼ばない(Radix の Tooltip は、既定の動きが止まっていると押した時に閉じない)。
 */
function guarded<E extends SyntheticEvent>(
    unavailable: boolean,
    handler: ((event: E) => void) | undefined,
) {
    return (event: E) => {
        if (unavailable) {
            event.preventDefault();

            return;
        }

        handler?.(event);
    };
}

/** Tooltip の中身(押せる = 名前、押せない = 名前と理由の 2 行) */
function TooltipText({ label, reason }: { label: string; reason?: string }) {
    if (reason === undefined) {
        return label;
    }

    return (
        <>
            <span className="block font-medium">{label}</span>
            {reason}
        </>
    );
}

/**
 * 表・一覧の行の操作の、名前つきのアイコンのボタン(枠つき・小。docs の 設計/画面の意匠/README.md の方針 12)。
 * 中身はアイコンだけで、名前は Tooltip と読み上げに出す。Dialog を開くときは DialogTrigger(asChild)で包む。
 * 押せない形は disabled でなく aria-disabled にする(disabled はマウスとフォーカスを受けず、理由の Tooltip を出せないため)。
 * 押しても何もせず、Tooltip も閉じない。
 * 説明の無いボタンには aria-describedby を属性ごと渡さない(Tooltip が開いている間に Radix が付ける説明を消さないため)。
 */
export function RowActionButton({
    label,
    destructive = false,
    disabledReason,
    className,
    onClick,
    onPointerDown,
    'aria-label': ariaLabel,
    'aria-describedby': describedBy,
    ...props
}: Props) {
    const reasonId = useId();
    const unavailable = disabledReason !== undefined;
    const descriptions = [describedBy, unavailable ? reasonId : undefined]
        .filter((id) => id !== undefined)
        .join(' ');

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="outline"
                    size="icon-sm"
                    aria-label={ariaLabel ?? label}
                    aria-disabled={unavailable || undefined}
                    className={cn(
                        destructive && 'text-destructive',
                        unavailable &&
                            'cursor-not-allowed opacity-50 hover:bg-background dark:hover:bg-input/30',
                        className,
                    )}
                    onPointerDown={guarded(unavailable, onPointerDown)}
                    onClick={guarded(unavailable, onClick)}
                    {...props}
                    {...(descriptions !== '' && {
                        'aria-describedby': descriptions,
                    })}
                />
            </TooltipTrigger>
            <TooltipContent>
                <TooltipText label={label} reason={disabledReason} />
            </TooltipContent>
            {unavailable && (
                <span id={reasonId} className="sr-only">
                    {disabledReason}
                </span>
            )}
        </Tooltip>
    );
}
