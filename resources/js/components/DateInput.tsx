import type { ComponentProps, MouseEvent } from 'react';
import { Input } from '@/components/ui/input';

type Props = Omit<ComponentProps<typeof Input>, 'type'> & {
    type: 'date' | 'datetime-local';
};

/**
 * 日付・日時の入力(docs の 設計/画面の意匠/README.md 方針 9 (b)): Input の date・datetime-local を、クリックでネイティブのカレンダーを
 * 開く欄にする(showPicker())。フォーカス(Tab)では開かず、Tab で入った欄では手入力できる(クリックはカレンダーを開く = 部分を押して打つ操作より
 * カレンダーを優先するのが決定。ネイティブのカレンダーのボタンを押したときは、クリックの処理と重なる挙動を目視で確かめる項目)。
 * 開けないとき(対応しないブラウザ・クロスオリジンの iframe)は何もせず、ネイティブの欄がそのまま使える。
 * components/ui/input.tsx(shadcn の部品)は書き換えず、ここで包む(方針 2)。
 */
export function DateInput({ onClick, ...props }: Props) {
    const open = (event: MouseEvent<HTMLInputElement>) => {
        onClick?.(event);

        try {
            event.currentTarget.showPicker();
        } catch {
            // 開けない環境(NotAllowedError・SecurityError・未対応)では、ネイティブの欄のまま使う
        }
    };

    return <Input {...props} onClick={open} />;
}
