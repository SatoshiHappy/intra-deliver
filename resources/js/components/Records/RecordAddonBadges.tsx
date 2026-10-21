import { StatusBadge } from '@/components/StatusBadge';
import { Badge } from '@/components/ui/badge';
import type { RecordAddonBadge } from '@/types';

/**
 * 後ろのモジュールが足す札の並び(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)。段階(tone)の無い札は状態色を持たないので、
 * 枠だけの札にする。
 */
export function RecordAddonBadges({ badges }: { badges: RecordAddonBadge[] }) {
    if (badges.length === 0) {
        return null;
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            {badges.map((badge, index) =>
                badge.tone === null ? (
                    <Badge key={index} variant="outline">
                        {badge.label}
                    </Badge>
                ) : (
                    <StatusBadge
                        key={index}
                        label={badge.label}
                        tone={badge.tone}
                    />
                ),
            )}
        </span>
    );
}
