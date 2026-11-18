import { Link } from '@inertiajs/react';
import type { RecordHoldLink } from '@/types';

/**
 * 持ち主の画面へのリンク(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1 と
 * 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.1)。更新履歴の欄(共通の部品)も使うので直下に置く。
 */
export function HoldLink({ link }: { link: RecordHoldLink }) {
    return (
        <Link
            href={link.url}
            className="text-primary underline-offset-4 hover:underline"
        >
            {link.label}
        </Link>
    );
}
