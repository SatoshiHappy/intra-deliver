import { FormBlock, invalidProps } from '@/components/FormBlock';
import type { MeetingImportSource } from '@/components/Meeting/createMeetingForm';
import {
    acceptedFiles,
    IMPORT_FILE_ID,
    importFileGuide,
} from '@/components/Meeting/createMeetingForm';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

const TEXT_ID = 'meeting-import-text';

type Props = {
    /** 音声 1 本の上限(バイト。案内に出す) */
    maxBytes: number;
    /** 音声の取り込みを先に止めている(ファイルの欄はテキストだけを受ける) */
    audioBlocked: boolean;
    source: MeetingImportSource;
    onSourceChange: (source: MeetingImportSource) => void;
    text: string;
    onTextChange: (text: string) => void;
    onFileChange: (file: File | null) => void;
    /** 欄の下に出す誤り(file・text) */
    errors: { file?: string; text?: string };
};

/** 札の中の欄の「必須」(どちらの札も、その札の欄が要る) */
const required = <Badge variant="secondary">必須</Badge>;

/**
 * ファイルの欄(1 つ選ぶ)。
 */
function ImportFile({
    maxBytes,
    audioBlocked,
    error,
    onChange,
}: {
    maxBytes: number;
    audioBlocked: boolean;
    error: string | undefined;
    onChange: (file: File | null) => void;
}) {
    return (
        <FormBlock
            id={IMPORT_FILE_ID}
            label="ファイル"
            labelAddon={required}
            hint={importFileGuide(maxBytes)}
            error={error}
        >
            <Input
                id={IMPORT_FILE_ID}
                type="file"
                accept={acceptedFiles(audioBlocked)}
                aria-required
                {...invalidProps(IMPORT_FILE_ID, error)}
                onChange={(event) => onChange(event.target.files?.[0] ?? null)}
            />
        </FormBlock>
    );
}

/**
 * 貼り付けの欄。
 */
function ImportText({
    text,
    error,
    onChange,
}: {
    text: string;
    error: string | undefined;
    onChange: (text: string) => void;
}) {
    return (
        <FormBlock
            id={TEXT_ID}
            label="テキスト"
            labelAddon={required}
            error={error}
        >
            <Textarea
                id={TEXT_ID}
                value={text}
                aria-required
                {...invalidProps(TEXT_ID, error)}
                onChange={(event) => onChange(event.target.value)}
                className="max-h-60 min-h-32"
            />
        </FormBlock>
    );
}

/**
 * 取り込むものの札「ファイル」「貼り付け」(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.2)。札を替えても選んだファイルが欄に残るよう、
 * 両方の札の中身を描いたままにする(隠した札の中身は送らない = 送る形は選んだ札の方だけ)。
 */
export function MeetingImportInput(props: Props) {
    const { source, errors } = props;

    return (
        <Tabs
            value={source}
            onValueChange={(next) =>
                props.onSourceChange(next === 'paste' ? 'paste' : 'file')
            }
        >
            <TabsList aria-label="取り込むもの">
                <TabsTrigger value="file">ファイル</TabsTrigger>
                <TabsTrigger value="paste">貼り付け</TabsTrigger>
            </TabsList>
            <TabsContent value="file" forceMount hidden={source !== 'file'}>
                <ImportFile
                    maxBytes={props.maxBytes}
                    audioBlocked={props.audioBlocked}
                    error={errors.file}
                    onChange={props.onFileChange}
                />
            </TabsContent>
            <TabsContent value="paste" forceMount hidden={source !== 'paste'}>
                <ImportText
                    text={props.text}
                    error={errors.text}
                    onChange={props.onTextChange}
                />
            </TabsContent>
        </Tabs>
    );
}
