import { Building2 } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { PARTY_KIND_NAMES } from '@/lib/recordParty';
import { cn } from '@/lib/utils';
import type { RecordPartyKind } from '@/types';

/** 印の大きさ: chip = 複数の参照の欄のチップの頭、inline = 値の表示の文字の前 */
type MarkSize = 'chip' | 'inline';

const SIZES: Record<MarkSize, { box: string; initial: string; icon: string }> =
    {
        chip: { box: 'size-[26px]', initial: 'text-xs', icon: 'size-[15px]' },
        inline: { box: 'size-4', initial: 'text-[10px]', icon: 'size-2.5' },
    };

/**
 * 相手の種類の印(ユーザーは頭文字の丸、組織は組織のアイコン。合意したチップの形 = docs の 設計/業務データの入れ物/画面/README.md 1.3)。
 * announced = 種類を読み上げる(ユーザーと組織が混ざりうる値)。偽なら飾り(aria-hidden。種類が 1 つの項目のチップ)。
 */
export function PartyMark({
    kind,
    label,
    size,
    announced,
}: {
    kind: RecordPartyKind;
    label: string;
    size: MarkSize;
    announced: boolean;
}) {
    const sized = SIZES[size];
    const a11y = announced
        ? { role: 'img', 'aria-label': PARTY_KIND_NAMES[kind] }
        : { 'aria-hidden': true };

    return kind === 'user' ? (
        <Avatar className={cn(sized.box, 'shrink-0')} {...a11y}>
            <AvatarFallback
                className={cn(
                    'bg-primary font-bold text-primary-foreground',
                    sized.initial,
                )}
            >
                {Array.from(label)[0]}
            </AvatarFallback>
        </Avatar>
    ) : (
        <span
            {...a11y}
            className={cn(
                'grid shrink-0 place-items-center rounded-full bg-accent text-primary',
                sized.box,
            )}
        >
            <Building2 aria-hidden="true" className={sized.icon} />
        </span>
    );
}
