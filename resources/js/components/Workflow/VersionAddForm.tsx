import { DateInput } from '@/components/DateInput';
import { FormBlock, invalidProps } from '@/components/FormBlock';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useVersionAddForm } from '@/hooks/Workflow/use-version-add-form';
import {
    versionChangeText,
    versionStartBounds,
} from '@/lib/Workflow/flowVersionAdd';
import { versionLabel } from '@/lib/Workflow/flowDefinitionTexts';

const START_ID = 'version-start-date';
const SOURCE_ID = 'version-source';

/**
 * 開始日の欄(min = 今日と最後の版の開始日の翌日の遅い方、max = 今日 + 1 年)。
 */
function StartDateField({
    min,
    max,
    value,
    error,
    onChange,
}: {
    min: string;
    max: string;
    value: string;
    error: string | undefined;
    onChange: (value: string) => void;
}) {
    return (
        <FormBlock
            id={START_ID}
            label="開始日"
            labelAddon={<Badge variant="secondary">必須</Badge>}
            error={error}
        >
            <DateInput
                type="date"
                id={START_ID}
                min={min}
                max={max}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                {...invalidProps(START_ID, error)}
            />
        </FormBlock>
    );
}

/**
 * 複製元の版の欄(見出し「複製元の版」と、最後の版の番号の枠の無い文字。変えられない)。
 */
function SourceVersionField({ lastNumber }: { lastNumber: number }) {
    return (
        <FormBlock
            id={SOURCE_ID}
            label="複製元の版"
            help="ルートの形と設定を複製して作成します。"
        >
            <p
                id={SOURCE_ID}
                className="flex h-9 items-center text-sm"
                {...invalidProps(SOURCE_ID, undefined, true)}
            >
                {versionLabel(lastNumber)}
            </p>
        </FormBlock>
    );
}

/**
 * 「追加時の変更」の 1 行(入力に応じて変わる表示なので画面に残す。緑みの囲み)。
 */
function ChangeNotice({ text }: { text: string }) {
    return (
        <div className="flex flex-col gap-1 rounded-lg bg-secondary px-4 py-3 text-sm text-secondary-foreground">
            <span className="font-semibold">追加時の変更</span>
            <p>{text}</p>
        </div>
    );
}

/**
 * ボタン「キャンセル」「決定」(右寄せで「決定」が右端)。
 */
function AddFormFooter({ isProcessing }: { isProcessing: boolean }) {
    return (
        <DialogFooter>
            <DialogClose asChild>
                <Button type="button" variant="outline">
                    キャンセル
                </Button>
            </DialogClose>
            <Button type="submit" disabled={isProcessing}>
                決定
            </Button>
        </DialogFooter>
    );
}

/**
 * 版を追加するダイアログの中身(開くたびに作り直す = 呼び手の Dialog が閉じると外れる。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 4 節): 開始日(既定 = min)と複製元の版(最後の版。「?」に説明)を横に並べ、
 * 下に追加時の変更の 1 行(緑みの囲み)。範囲の判定はサーバーが持つ。
 */
export function VersionAddForm({
    flowCode,
    flowName,
    lastNumber,
    lastStartDate,
    today,
    onSucceeded,
}: {
    flowCode: string;
    flowName: string;
    lastNumber: number;
    lastStartDate: string;
    /** 今日(YYYY-MM-DD。Asia/Tokyo) */
    today: string;
    onSucceeded: () => void;
}) {
    const bounds = versionStartBounds(today, lastStartDate);
    const form = useVersionAddForm(flowCode, bounds.min, onSucceeded);
    const change = versionChangeText(lastNumber, form.startDate);

    return (
        <form noValidate onSubmit={form.submit} className="flex flex-col gap-4">
            <DialogHeader>
                <DialogTitle>版を追加する</DialogTitle>
                <DialogDescription>
                    {`${flowName}に新しい版を追加します。`}
                </DialogDescription>
            </DialogHeader>
            <div className="grid grid-cols-2 items-start gap-4">
                <StartDateField
                    min={bounds.min}
                    max={bounds.max}
                    value={form.startDate}
                    error={form.error}
                    onChange={form.setStartDate}
                />
                <SourceVersionField lastNumber={lastNumber} />
            </div>
            {change !== null && <ChangeNotice text={change} />}
            <AddFormFooter isProcessing={form.isProcessing} />
        </form>
    );
}
