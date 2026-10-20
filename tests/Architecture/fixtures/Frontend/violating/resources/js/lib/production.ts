// テスト専用の手助けを本番のコードが import する(禁止)
import { builder } from './builder.testing';

export const production = builder;
