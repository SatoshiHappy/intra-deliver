import { HoldLink } from '@/components/HoldLink';
import { StatusBadge } from '@/components/StatusBadge';
import type { RecordHold } from '@/types';

/**
 * レコードの持ち主の 1 行(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1)。詳細と編集の見出しの下に置く。
 */
export function RecordHoldNotice({ hold }: { hold: RecordHold }) {
    const state = hold.state;

    return (
        <p className="-mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
            <span>{hold.reason}</span>
            {state !== null && (
                <>
                    <HoldLink link={state.link} />
                    <span className="flex items-center gap-1">
                        <StatusBadge label={state.status} tone={state.tone} />
                        {state.suffix !== null && <span>・{state.suffix}</span>}
                    </span>
                </>
            )}
        </p>
    );
}
