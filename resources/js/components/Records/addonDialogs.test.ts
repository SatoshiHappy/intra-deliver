import { describe, expect, it } from 'vite-plus/test';
import { recordAddonDialog, registerRecordAddonDialog } from './addonDialogs';

/** 部品の代わり(描画はしない。表が同じものを返すかだけを見る) */
const dialog = () => null;

describe('registerRecordAddonDialog', () => {
    it('登録した部品を名前で引け、登録の無い名前は undefined', () => {
        registerRecordAddonDialog('meeting.create', dialog);

        expect(recordAddonDialog('meeting.create')).toBe(dialog);
        expect(recordAddonDialog('meeting.recreate')).toBeUndefined();
    });

    it('同じ名前を 2 度登録するのは作りの誤り', () => {
        registerRecordAddonDialog('meeting.share', dialog);

        expect(() =>
            registerRecordAddonDialog('meeting.share', dialog),
        ).toThrow('meeting.share');
    });
});
