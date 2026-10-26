import { router } from '@inertiajs/react';
import { adminProxiesUrl } from '@/lib/Workflow/proxyList';
import type { WorkflowAdminProxyQuery } from '@/types';

/** 絞り込み・ページを変えたときに取り直す props */
const PROXY_LIST_PROPS = ['query', 'proxies'];

/**
 * 一覧を、絞り込み・ページを変えた URL へ部分の再読み込みで移る(URL に残すので、再読み込み・戻る操作で同じ一覧になる。絞り込みを変えたらページは 1 に戻す)。
 */
export function visitProxyList(query: WorkflowAdminProxyQuery, page = 1): void {
    router.get(
        adminProxiesUrl(query, page),
        {},
        { only: PROXY_LIST_PROPS, preserveState: true, preserveScroll: true },
    );
}
