import { CircleQuestionMark } from 'lucide-react';
import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type Props = {
    /** 入力の id(ラベルの htmlFor。エラーの文言の id は <id>-error) */
    id: string;
    label: string;
    /** 管理の画面の、空で送ると業務処理が拒む欄か(ラベルの右に赤い *。{@link RequiredMark}) */
    isRequired?: boolean;
    /** ラベルの横に出すもの(「必須」の札など。入力の名前には入らない) */
    labelAddon?: ReactNode;
    /** 入力の説明(ラベルの横の「?」+ Tooltip。{@link FieldHelp}。入力は invalidProps(id, error, true) で説明文に結ぶ) */
    help?: ReactNode;
    /** 入力の下の説明 */
    hint?: string;
    /** 入力の下のエラーの文言(text-destructive) */
    error?: string;
    /** 欄の外側の追加の class(格子の中で 2 列分にするときなど) */
    className?: string;
    children: ReactNode;
};

/**
 * エラーの文言の要素の id(入力の aria-describedby に使う)。
 */
export function formErrorId(id: string): string {
    return `${id}-error`;
}

/**
 * 説明文({@link FieldHelp})の読み上げ用の要素の id(入力の aria-describedby に使う)。
 */
function fieldHelpId(id: string): string {
    return `${id}-help`;
}

/**
 * 文字数の要素({@link CountedTextInput} の「N / 上限」)の id(入力の aria-describedby に使う)。
 */
export function characterCountId(id: string): string {
    return `${id}-count`;
}

/**
 * 入力に付ける属性。エラーがあれば aria-invalid とエラーの文言への aria-describedby、説明({@link FieldHelp})・文字数
 * があればそれらへの aria-describedby(エラー・説明・文字数の順)。どれも無ければ空。hasCount は {@link CountedTextInput} だけが使う。
 */
export function invalidProps(
    id: string,
    error: string | undefined,
    hasHelp = false,
    hasCount = false,
): { 'aria-invalid'?: true; 'aria-describedby'?: string } {
    const describedBy = [
        ...(error === undefined ? [] : [formErrorId(id)]),
        ...(hasHelp ? [fieldHelpId(id)] : []),
        ...(hasCount ? [characterCountId(id)] : []),
    ];

    return {
        ...(error === undefined ? {} : { 'aria-invalid': true }),
        ...(describedBy.length === 0
            ? {}
            : { 'aria-describedby': describedBy.join(' ') }),
    };
}

/**
 * 欄の下に出さないキーの誤りをフォームの先頭に出す(管理の画面.md 3 節の冒頭)。shownKeys = その画面が欄の下に出すキー。
 * tabIndex は、送った後に最初の誤りへ focus() で移せるように(業務のフォーム。業務の画面.md 2.2)いつも付ける。
 */
export function UnshownErrors({
    errors,
    shownKeys,
}: {
    errors: Record<string, string | undefined>;
    shownKeys: string[];
}) {
    const unshown = Object.keys(errors)
        .filter((key) => !shownKeys.includes(key))
        .sort();

    return unshown.map((key) => (
        <p
            key={key}
            role="alert"
            tabIndex={-1}
            className="text-sm text-destructive"
        >
            {errors[key]}
        </p>
    ));
}

/**
 * 必須の入力の印(docs の 設計/業務データの入れ物/画面/README.md 5 節: 管理の画面のフォームで、空で送ると業務処理が拒む欄のラベルの右に赤い *)。
 * 印の描き方はここ 1 か所。FormBlock の isRequired と、ラベルが表の見出しにある欄(選択肢の表)・欄の群の見出しが使う。
 * ラベルの外に置き、読み上げない(欄の名前を変えない = E2E と支援技術が欄をラベルで引けるように)。業務のフォームは「必須」の札のまま(2.2)。
 */
export function RequiredMark() {
    return (
        <span aria-hidden="true" className="text-destructive">
            *
        </span>
    );
}

/**
 * 入力欄の説明(docs の 設計/画面の意匠/README.md 方針 9(d): ラベルの横の「?」のアイコン + Tooltip。マウスオーバーとキーボードのフォーカスで出す)。
 * 描き方はここ 1 か所。FormBlock の help と、FormBlock を使わない横並びの欄(ラベルが左)が自分の Label の横に置く。
 * 「?」は Label の外のボタン(欄の名前を変えない)で、読み上げの名前は「<ラベル>の説明」。説明文は読み上げ用の見えない要素にも置き、
 * 入力が invalidProps(id, error, true) の aria-describedby で結ぶ(Tooltip は開いている間だけ「?」に結ばれるので、入力にフォーカスした読み上げにも届くように)。
 * id = 入力の id、label = 欄のラベル。
 */
export function FieldHelp({
    id,
    label,
    children,
}: {
    id: string;
    label: string;
    children: ReactNode;
}) {
    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        aria-label={`${label}の説明`}
                        className="inline-flex rounded-full text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <CircleQuestionMark
                            className="size-4"
                            aria-hidden="true"
                        />
                    </button>
                </TooltipTrigger>
                <TooltipContent>{children}</TooltipContent>
            </Tooltip>
            <span id={fieldHelpId(id)} className="sr-only">
                {children}
            </span>
        </>
    );
}

/**
 * フォームの 1 つの欄(ラベル・ラベルの横の札・入力・説明・エラーを縦に並べる)。欄の全体を role="group" にしてラベルで
 * 名付ける(E2E と支援技術が欄の単位で引けるように)。業務のフォーム・管理のフォーム・規則の Dialog が使う。
 */
export function FormBlock({
    id,
    label,
    isRequired = false,
    labelAddon,
    help,
    hint,
    error,
    className,
    children,
}: Props) {
    return (
        <div
            role="group"
            aria-labelledby={`${id}-label`}
            className={cn('flex flex-col gap-2', className)}
        >
            <div className="flex items-center gap-2">
                <Label id={`${id}-label`} htmlFor={id}>
                    {label}
                </Label>
                {isRequired && <RequiredMark />}
                {help !== undefined && (
                    <FieldHelp id={id} label={label}>
                        {help}
                    </FieldHelp>
                )}
                {labelAddon}
            </div>
            {children}
            {hint !== undefined && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            {error !== undefined && (
                <p id={formErrorId(id)} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
