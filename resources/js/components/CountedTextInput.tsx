import type { ComponentProps } from 'react';
import { characterCountId, invalidProps } from '@/components/FormBlock';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { countCharacters } from '@/lib/textLength';
import { cn } from '@/lib/utils';

type OwnProps = {
    /** 入力の id(誤りの文言・説明・文字数の要素の id もここから作る) */
    id: string;
    value: string;
    /** 上限(サーバーの上限を props で受ける) */
    max: number;
    /** 誤りがある(誤りの文言は使い手が formErrorId(id) の要素で描く) */
    invalid?: boolean;
    /** 説明({@link FieldHelp})がある */
    hasHelp?: boolean;
    /** 文字数の要素の追加の class(間隔を持たない置き場 = 表のセルの余白など) */
    countClassName?: string;
};

/** 部品が組み立てる属性(使い手は渡せない) */
type BuiltAttributes = keyof OwnProps | 'aria-invalid' | 'aria-describedby';

type Props =
    | (OwnProps & { isMultiline?: false } & Omit<
              ComponentProps<'input'>,
              BuiltAttributes
          >)
    | (OwnProps & { isMultiline: true } & Omit<
              ComponentProps<'textarea'>,
              BuiltAttributes
          >);

/**
 * 「N / 上限」。超えたら赤字で超えた数を添える。送信は止めず aria-invalid にもしない(誤りの正はサーバーの 422)。
 * 札を持つ複数行の入力({@link RichTextInput})も、札の外の欄の下にこの要素を置く。
 */
export function CharacterCount({
    inputId,
    value,
    max,
    className,
}: {
    inputId: string;
    value: string;
    max: number;
    className?: string;
}) {
    const count = countCharacters(value);
    const over = count - max;

    return (
        <p
            id={characterCountId(inputId)}
            className={cn(
                'text-right font-num text-xs tabular-nums',
                over > 0 ? 'text-destructive' : 'text-muted-foreground',
                className,
            )}
        >
            {`${count} / ${max}`}
            {over > 0 && `(${over} 文字超過)`}
        </p>
    );
}

/**
 * 文字列の入力と、その下の文字数(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2「文字数」)。数を出す欄はどの画面でもこの部品。
 * maxLength は付けない(貼り付けで黙って切らない)。包みの要素を持たない(間隔は使い手の並びが決める)。
 */
export function CountedTextInput(props: Props) {
    const {
        id,
        value,
        max,
        invalid = false,
        hasHelp = false,
        countClassName,
    } = props;
    // invalidProps は誤りの文言の有無だけを見る(文言は使い手が描く)
    const built = {
        id,
        value,
        ...invalidProps(id, invalid ? '' : undefined, hasHelp, true),
    };

    return (
        <>
            {props.isMultiline === true ? (
                <Textarea {...inputAttributes(props)} {...built} />
            ) : (
                <Input {...inputAttributes(props)} {...built} />
            )}
            <CharacterCount
                inputId={id}
                value={value}
                max={max}
                className={countClassName}
            />
        </>
    );
}

/**
 * 使い手が渡した入力の属性(部品の props を除いた残り)。
 */
function inputAttributes<T extends Props>(
    props: T,
): Omit<T, keyof OwnProps | 'isMultiline'> {
    const {
        id: _id,
        value: _value,
        max: _max,
        invalid: _invalid,
        hasHelp: _hasHelp,
        countClassName: _countClassName,
        isMultiline: _isMultiline,
        ...attributes
    } = props;

    return attributes;
}
