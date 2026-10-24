import { FormBlock, invalidProps } from '@/components/FormBlock';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { WorkflowPrincipal } from '@/types';

const PRINCIPAL_ID = 'case-action-principal';

/**
 * 処理担当者の欄(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節。選択肢が 2 つ以上のときだけ呼ぶ)。選択肢は名前の後に全角の空白を
 * 置いた「自分」「代理元として」。value = 選んだ人の id の文字列、note = 代理元を選んだときの 1 行。
 */
export function CaseActionPrincipalField({
    choices,
    value,
    note,
    error,
    onChange,
}: {
    choices: WorkflowPrincipal[];
    value: string;
    note: string | null;
    error: string | undefined;
    onChange: (value: string) => void;
}) {
    return (
        <FormBlock
            id={PRINCIPAL_ID}
            label="処理担当者"
            labelAddon={<Badge variant="secondary">必須</Badge>}
            help="自分と、代理元のうち、この申請の処理担当者に該当する人から選択します。該当する人が 1 人だけの場合、この欄は表示されません。"
            error={error}
        >
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger
                    id={PRINCIPAL_ID}
                    className="w-full"
                    {...invalidProps(PRINCIPAL_ID, error, true)}
                >
                    <SelectValue placeholder="選択してください" />
                </SelectTrigger>
                <SelectContent>
                    {choices.map((choice) => (
                        <SelectItem key={choice.id} value={String(choice.id)}>
                            {`${choice.name}　${choice.isSelf ? '自分' : '代理元として'}`}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {note !== null && (
                <p className="text-sm text-muted-foreground">{note}</p>
            )}
        </FormBlock>
    );
}
