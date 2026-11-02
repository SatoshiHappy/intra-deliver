import { router, usePage } from '@inertiajs/react';
import { show as overview } from '@/routes/authz/settings';

/** 判定する人を替えたときに取り直す props(candidates・resourceTypes は取り直さない) */
const JUDGED_PROPS = ['grid', 'judge'];

/** 種類を替えたときに取り直す props */
const TYPED_PROPS = ['grid'];

/** 機能の絞り込みのクエリの名前(画面だけが読む) */
const FEATURE = 'feature';

/**
 * 認可設定の選択(docs の 認可設定の画面.md 1.4・2.1・2.3): 判定する人 = ?user、リソースの種類 = ?type、機能 = ?feature。
 * 判定する人を選ぶと、ほかのクエリを残して部分再読み込みする。種類を選ぶと、feature を外して(「すべて」に戻す)部分再読み込みする。
 * 機能を選ぶと、サーバーへ要求を送らずに URL のクエリだけを替え、履歴に 1 つ積む(Inertia の client-side visit = router.push)。
 */
export function useOverviewVisit() {
    const { url } = usePage();
    const params = new URL(url, 'http://localhost').searchParams;

    const current = (): Record<string, string> => Object.fromEntries(params);

    const withoutFeature = (): Record<string, string> => {
        const query = current();
        delete query[FEATURE];

        return query;
    };

    const visit = (query: Record<string, string>, only: string[]) => {
        router.get(
            overview.url({ query }),
            {},
            { only, preserveState: true, preserveScroll: true },
        );
    };

    return {
        /** クエリ feature の値(無ければ null。深さ 1 の行に当たるかは呼ぶ側が見る) */
        feature: params.get(FEATURE),
        chooseUser: (value: string) =>
            visit({ ...current(), user: value }, JUDGED_PROPS),
        chooseType: (value: string) =>
            visit({ ...withoutFeature(), type: value }, TYPED_PROPS),
        /** code = 選んだ深さ 1 の行のコード(null = すべて) */
        chooseFeature: (code: string | null) =>
            router.push({
                url: overview.url({
                    query:
                        code === null
                            ? withoutFeature()
                            : { ...withoutFeature(), [FEATURE]: code },
                }),
                preserveState: true,
                preserveScroll: true,
            }),
    };
}
