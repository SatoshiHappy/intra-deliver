import { router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { RecordShowTabs } from '@/components/RecordShowTabs';
import { RELATED_TAB } from '@/lib/recordRelated';
import { selectedTabKey, urlWithQuery } from '@/lib/recordShow';

/** 左のタブ 1 つ(body = 選んだときの中身。count = 札の件数、menu = 関連レコードの入口のメニュー) */
export type RecordFrameTab = {
    key: string;
    label: string;
    count?: number | null;
    menu?: ReactNode;
    body: ReactNode;
};

/**
 * 詳細の左のタブの枠(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 3 節・4 節): 選んだタブは URL のクエリ tab に映す
 * (キーの一覧で照らし、ほかは詳細タブ)。中身が props にあるタブは URL だけを置き換え、関連レコードは onRelated(表が無ければ部分の再読み込み。
 * 渡さなければ URL だけ)。中身を作る側(tabs)が、レコードの今の値で描くか版の写しで描くかを決める。
 */
export function RecordTabsFrame({
    tabs,
    onRelated,
}: {
    tabs: RecordFrameTab[];
    onRelated?: () => void;
}) {
    const { url } = usePage();
    const selected = selectedTabKey(
        url,
        tabs.map((tab) => tab.key),
    );
    const select = (key: string) =>
        key === RELATED_TAB && onRelated !== undefined
            ? onRelated()
            : router.replace({
                  url: urlWithQuery(url, { tab: key }),
                  preserveState: true,
                  preserveScroll: true,
              });

    return (
        <RecordShowTabs
            tabs={tabs.map((tab) => ({
                key: tab.key,
                label: tab.label,
                count: tab.count ?? null,
                menu: tab.menu,
            }))}
            selected={selected}
            onSelect={select}
        >
            {tabs.find((tab) => tab.key === selected)?.body}
        </RecordShowTabs>
    );
}
