import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useOverviewVisit } from '@/hooks/Authz/useOverviewVisit';
import { featureOf, featureOptions } from '@/lib/Authz/gridView';
import { nameOrCode } from '@/lib/nameOrCode';
import type { AuthzGridRow } from '@/types';

/** 表示の範囲の選択で「すべて」を表す値(ほかの選択肢の値はリソースグループの ID の数字なので重ならない) */
const ALL_FEATURES = 'all';

const FEATURE_ID = 'authz-feature';

type Props = {
    /** 格子の行 */
    rows: AuthzGridRow[];
};

/**
 * 選択の 2 行目「表示の範囲」(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.3 の「表示の範囲」)。先頭に「すべて」、続けて深さ 1 の行(表示名か、無ければコード)。
 * 選んでいる値は URL のクエリ feature(深さ 1 の行に無い値は「すべて」)。選ぶと URL のクエリ feature だけを替える(サーバーへ要求を送らない)。
 */
export function FeatureField({ rows }: Props) {
    const { feature: code, chooseFeature } = useOverviewVisit();
    const features = featureOptions(rows);
    const feature = featureOf(rows, code);
    const choose = (value: string) =>
        chooseFeature(
            features.find((row) => String(row.resourceGroupId) === value)
                ?.code ?? null,
        );

    return (
        <div className="flex min-h-9 items-center gap-2.5">
            <div className="w-32 flex-none">
                <Label htmlFor={FEATURE_ID}>表示の範囲</Label>
            </div>
            <Select
                value={
                    feature === null
                        ? ALL_FEATURES
                        : String(feature.resourceGroupId)
                }
                onValueChange={choose}
            >
                <SelectTrigger id={FEATURE_ID} className="min-w-48">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL_FEATURES}>すべて</SelectItem>
                    {features.map((row) => (
                        <SelectItem
                            key={row.resourceGroupId}
                            value={String(row.resourceGroupId)}
                        >
                            {nameOrCode(row)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
