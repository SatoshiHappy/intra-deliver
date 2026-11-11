import { formErrorId } from '@/components/FormBlock';
import { HeadingHelp } from '@/components/HeadingHelp';
import { Button } from '@/components/ui/button';
import { BooleanChoiceRadios } from '@/components/Workflow/BooleanChoiceRadios';
import { useFlowSettingsForm } from '@/hooks/Workflow/use-flow-settings-form';
import { ATTACHMENT_USE_CHOICES } from '@/lib/Workflow/booleanChoice';
import { FLOW_SETTINGS_ERROR_KEY } from '@/lib/Workflow/flowSettingsForm';

/** 面「添付ファイル」の見出しの説明(見出しの横の「?」= 設計/画面の意匠/README.md の方針 9(d)) */
const ATTACHMENT_HELP =
    '申請と処理で、添付ファイルを使用するかどうかを設定します。申請中の申請は申請した時の設定のまま進みます。使用しない場合も、すでに追加済みのファイルは表示できます。';

const HEADING_ID = 'flow-attachment-heading';
const FIELD_ID = 'flow-attachment-enabled';

/**
 * 見出しと横の「?」。
 */
function SurfaceHeading() {
    return (
        <div className="flex items-center gap-2">
            <h2 id={HEADING_ID} className="text-[15px] font-semibold">
                添付ファイル
            </h2>
            <HeadingHelp label="添付ファイル">{ATTACHMENT_HELP}</HeadingHelp>
        </div>
    );
}

/**
 * 面「添付ファイル」(docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節): 見出しと横の「?」、ラジオ「使用する」「使用しない」
 * (幅は面「参照者」と同じ max-w-120)、「キャンセル」「保存」(ボタンの列は欄の並びの左端 = 設計/画面の意匠/README.md の方針 11)。
 * 保存は面ごとで、真偽を 1 つ送る。呼び手は、サーバーの値が替わったら key を替えて作り直す。
 */
export function FlowAttachmentSurface({
    flowCode,
    isAttachmentEnabled,
}: {
    flowCode: string;
    isAttachmentEnabled: boolean;
}) {
    const form = useFlowSettingsForm(flowCode, isAttachmentEnabled);
    const error = form.errors[FLOW_SETTINGS_ERROR_KEY];

    return (
        <form
            noValidate
            onSubmit={form.submit}
            aria-labelledby={HEADING_ID}
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <SurfaceHeading />
            <div className="flex w-full max-w-120 flex-col gap-2">
                <BooleanChoiceRadios
                    id={FIELD_ID}
                    labelledBy={HEADING_ID}
                    value={form.isAttachmentEnabled}
                    choices={ATTACHMENT_USE_CHOICES}
                    error={error}
                    onChange={form.change}
                />
                {error !== undefined && (
                    <p
                        id={formErrorId(FIELD_ID)}
                        className="text-sm text-destructive"
                    >
                        {error}
                    </p>
                )}
            </div>
            <div className="flex gap-2">
                <Button type="button" variant="outline" onClick={form.cancel}>
                    キャンセル
                </Button>
                <Button type="submit" disabled={form.isProcessing}>
                    保存
                </Button>
            </div>
        </form>
    );
}
