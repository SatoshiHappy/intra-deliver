import type {
    ApplicationTargetFailure,
    ApplicationTargetFetch,
} from '@/lib/Workflow/applicationTargetFlow';
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

/** 写しの値を取れなかったときのトーストの文(403・404 とほかの失敗で分ける。文の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 8 節) */
const FAILED_MESSAGES: Record<ApplicationTargetFailure, string> = {
    unavailable: '申請対象の値を取得できませんでした。',
    failed: '申請対象の値を取得できませんでした。時間をおいて、もう一度選択してください。',
};

/**
 * 写しの値を取れなかったときのトーストの文。
 */
export function failedMessageOf(failure: ApplicationTargetFailure): string {
    return FAILED_MESSAGES[failure];
}

/** 写しの値を取れない応答のうち、トーストの文を分ける状態(403・404。ほかは時間をおいてやり直す文) */
const UNAVAILABLE_STATUSES = [403, 404];

/**
 * HTTP の失敗の理由(403・404 の応答 = unavailable、ほかの応答の誤り・取り消し・通信の失敗 = failed)。
 */
function failureOf(error: unknown): ApplicationTargetFailure {
    const status =
        error instanceof Error && 'response' in error
            ? (error.response as { status?: unknown } | null)?.status
            : undefined;

    return typeof status === 'number' && UNAVAILABLE_STATUSES.includes(status)
        ? 'unavailable'
        : 'failed';
}

/**
 * useHttp の get を、写しの流れの取り方にする。HTTP の失敗は理由(403・404 か、ほか)に、値を返さない応答(検証の誤りの 422)は
 * failed にし、呼び手の誤りは失敗に混ぜて隠さないよう投げ直す(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」、
 * トーストの文 = 同 8 節)。
 */
export function fetchOf(
    get: (url: string) => Promise<ApplicationTargetSnapshot | undefined>,
): ApplicationTargetFetch {
    return async (url) => {
        try {
            return (await get(url)) ?? 'failed';
        } catch (error) {
            if (isHttpFailure(error)) {
                return failureOf(error);
            }

            throw error;
        }
    };
}
