/**
 * 真偽 2 択のラジオの選択肢(docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節。面は使用する・使用しない、カードは追加できる・追加できない)。
 * ラジオの値は文字列なので、真偽との行き来をここに閉じ込める。
 */

/** 選択肢の 1 つ(value = 保存する真偽、label = 画面の語) */
export type BooleanChoice = { value: boolean; label: string };

/** 面「添付ファイル」の選択肢(並び = 使用する・使用しない。値 = 添付ファイルを使用するか) */
export const ATTACHMENT_USE_CHOICES: BooleanChoice[] = [
    { value: true, label: '使用する' },
    { value: false, label: '使用しない' },
];

/** カード「添付ファイル」の選択肢(並び = 追加できる・追加できない。値 = 添付ファイルを追加できないか) */
export const ATTACHMENT_ADD_CHOICES: BooleanChoice[] = [
    { value: false, label: '追加できる' },
    { value: true, label: '追加できない' },
];

/**
 * 真偽のラジオの値(文字列)。
 */
export function choiceKeyOf(value: boolean): string {
    return value ? 'true' : 'false';
}

/**
 * ラジオの値から選択肢の真偽(選択肢に無い値は null)。
 */
export function choiceValueOf(
    key: string,
    choices: BooleanChoice[],
): boolean | null {
    const choice = choices.find((one) => choiceKeyOf(one.value) === key);

    return choice === undefined ? null : choice.value;
}
