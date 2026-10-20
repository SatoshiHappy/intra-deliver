import { router, usePage } from '@inertiajs/react';
import { overview } from '@/routes/authz';

/** 判定する人を替えたときに取り直す props(candidates・resourceTypes は取り直さない) */
const JUDGED_PROPS = ['grid', 'judge'];

/** 種類を替えたときに取り直す props */
const TYPED_PROPS = ['grid'];

/**
 * 認可設定の選択(判定する人 = ?user、リソースの種類 = ?type)。選ぶと、もう一方のクエリを残して部分再読み込みする。
 */
export function useOverviewVisit() {
    const { url } = usePage();

    const visit = (changes: Record<string, string>, only: string[]) => {
        const current = Object.fromEntries(
            new URL(url, 'http://localhost').searchParams,
        );

        router.get(
            overview.url({ query: { ...current, ...changes } }),
            {},
            { only, preserveState: true, preserveScroll: true },
        );
    };

    return {
        chooseUser: (value: string) => visit({ user: value }, JUDGED_PROPS),
        chooseType: (value: string) => visit({ type: value }, TYPED_PROPS),
    };
}
