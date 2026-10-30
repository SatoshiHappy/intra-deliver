import type { ApplicationTargetFetch } from '@/lib/Workflow/applicationTargetFlow';
import type { ApplicationTargetSnapshot } from '@/types/workflowApplyTarget';

/** Inertia の useHttp が投げる HTTP の失敗の `code`(応答の誤り・取り消し・通信の失敗。実装は @inertiajs/core の httpErrors) */
const HTTP_FAILURE_CODES = [
    'ERR_HTTP_RESPONSE',
    'ERR_CANCELLED',
    'ERR_NETWORK',
];

/**
 * HTTP の失敗か(応答の誤り・取り消し・通信の失敗)。ほかの例外は呼び手の誤りなので、失敗として扱わない。
 */
export function isHttpFailure(error: unknown): boolean {
    return (
        error instanceof Error &&
        'code' in error &&
        HTTP_FAILURE_CODES.includes(String(error.code))
    );
}

/**
 * useHttp の get を、写しの流れの取り方にする。HTTP の失敗と、値を返さない応答(検証の誤りの 422)は取れなかった印の null にし、
 * 呼び手の誤りは失敗に混ぜて隠さないよう投げ直す(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」)。
 */
export function fetchOf(
    get: (url: string) => Promise<ApplicationTargetSnapshot | undefined>,
): ApplicationTargetFetch {
    return async (url) => {
        try {
            return (await get(url)) ?? null;
        } catch (error) {
            if (isHttpFailure(error)) {
                return null;
            }

            throw error;
        }
    };
}
