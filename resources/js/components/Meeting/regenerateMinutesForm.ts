/**
 * 「再作成」のダイアログの送る形と文(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.3)。書き起こしの有無で送り先の処理が分かれ
 * (処理.md の 3.7)、書き起こしが無い会議は音声から作り直すので追加の指示を送らない。
 */

/** 書き起こしが無く、追加の指示の欄の代わりに出す文 */
export const FROM_AUDIO_NOTE = '書き起こしが無いため、音声から作り直します。';

/** 送る形(書き起こしのある会議だけ追加の指示を持つ) */
export type RegeneratePayload = { instructions?: string };

/**
 * 送る形(書き起こしがあれば追加の指示、無ければ空 = 入口は追加の指示のある音声からの作り直しを断る)。
 */
export function regeneratePayload(
    hasTranscript: boolean,
    instructions: string,
): RegeneratePayload {
    return hasTranscript ? { instructions } : {};
}

/** 送るボタンの形と、欄の上に出す誤りから除くキー */
export type RegenerateView = {
    submitDisabled: boolean;
    shownErrorKeys: string[];
};

/**
 * 送るボタンを押せないか(音声の取り込みを先に止めているとき)と、欄の下に出すので欄の上からは除く誤りのキー
 * (書き起こしがあれば追加の指示の欄がある。無ければ欄が無いので、すべて欄の上に出す)。
 */
export function regenerateView(
    hasTranscript: boolean,
    audioBlockedMessage: string | null,
): RegenerateView {
    return {
        submitDisabled: audioBlockedMessage !== null,
        shownErrorKeys: hasTranscript ? ['instructions'] : [],
    };
}
