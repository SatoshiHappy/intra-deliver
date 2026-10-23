/**
 * 「再作成」のダイアログの送る形と描き分け(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.3)。判定はこの 1 か所に置き、部品は結果を読む。
 */

/** 書き起こしが無く、追加の指示の欄の代わりに出す文 */
export const FROM_AUDIO_NOTE = '書き起こしが無いため、音声から作り直します。';

/** 送る形(書き起こしのある会議だけ追加の指示を持つ) */
export type RegeneratePayload = { instructions?: string };

/**
 * 送る形(追加の指示の欄を出すときだけ追加の指示を送る。無いときに送ると入口が音声からの作り直しを断る)。
 */
export function regeneratePayload(
    showInstructions: boolean,
    instructions: string,
): RegeneratePayload {
    return showInstructions ? { instructions } : {};
}

/** 再作成のダイアログの描き分け(先の止め方の文・追加の指示の欄を出すか・送るボタンを押せないか・欄の下に出すので欄の上からは除く誤りのキー) */
export type RegenerateView = {
    blockedMessage: string | null;
    showInstructions: boolean;
    submitDisabled: boolean;
    shownErrorKeys: string[];
};

/**
 * 再作成のダイアログの描き分け。音声の取り込みの止め方は、音声から作り直す(書き起こしが無い)ときだけ効く。
 */
export function regenerateView({
    hasTranscript,
    audioBlockedMessage,
}: {
    hasTranscript: boolean;
    audioBlockedMessage: string | null;
}): RegenerateView {
    const blockedMessage = hasTranscript ? null : audioBlockedMessage;

    return {
        blockedMessage,
        showInstructions: hasTranscript,
        submitDisabled: blockedMessage !== null,
        shownErrorKeys: hasTranscript ? ['instructions'] : [],
    };
}
