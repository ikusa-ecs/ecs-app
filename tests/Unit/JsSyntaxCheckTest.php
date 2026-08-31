<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\JsSyntaxCheck;

/**
 * 見張りの道具そのものを見張るテスト（2026-08-31）。
 *
 * 【なぜ要るか】
 * 「画面は出るのに JavaScript だけ死んでいる」を見つけるための道具なので、
 * **これが見逃したら意味がない**。実際、最初の版は
 *   ・@verbatim の中に @json と書いてしまった形（2026-08-31 の本番事故）
 *   ・値が空になって「= ;」になった形
 * のどちらも見逃していた。過去に本当に起きた壊れ方を、ここに1つずつ残しておく。
 *
 * あわせて **誤検知しないこと**も確かめる。普通のコードを「壊れている」と言い出すと、
 * みんながテストを信用しなくなり、本物の事故が埋もれるため。
 */
class JsSyntaxCheckTest extends TestCase
{
    /** 過去に本当に起きた壊れ方は、必ず見つける。 */
    public function test_it_finds_the_ways_the_screens_actually_broke(): void
    {
        // ① @verbatim の中に Blade の書き方が残り、文字のまま出た（2026-08-31 本番）
        $this->assertNotEmpty(
            JsSyntaxCheck::problems('const a = [{ options:@json(\App\Support\ProfileOptions::drivingChoices()) }];'),
            '@json がそのまま出ている形を見逃しました'
        );

        // ② サーバーから渡す値が空になった（文字化けで @json が失敗・2026-08-31 本番）
        $this->assertNotEmpty(
            JsSyntaxCheck::problems('window.ECS_RECRUIT_JOBS = ;'),
            '中身が空の代入を見逃しました'
        );

        // ③ 改行をあらわす2文字が本物の改行に化けた（2026-08-26／08-28 本番・計3回）
        $this->assertNotEmpty(
            JsSyntaxCheck::problems("const s = ['a'].join('\n');"),
            '文字列が行の途中で切れている形を見逃しました'
        );

        // ④ かっこが閉じていない（貼り付けミス・切れたコード）
        $this->assertNotEmpty(
            JsSyntaxCheck::problems('function f() { if (x) { return 1; }'),
            'かっこの数が合っていない形を見逃しました'
        );
    }

    /** 普通のコードを「壊れている」と言わない（誤検知で本物が埋もれるのを防ぐ）。 */
    public function test_it_does_not_complain_about_normal_code(): void
    {
        $ok = <<<'JS'
        // コメント。'引用符' や "かっこ(" が入っていても平気。
        /* 複数行の
           コメント */
        const csv = rows.map(function (r) {
          return r.map(function (v) {
            v = (v == null) ? '' : String(v);
            return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
          }).join(',');
        }).join('\r\n');
        const html = `<div class="x" data-n="${String(p.name).replace(/"/g, '&quot;')}">
            ${items.map(i => `<span>${i.label}</span>`).join('')}
          </div>`;
        const re = /^\d{1,2}\s*\/\s*\d{1,2}$/u;
        const path = 'a/b/c';
        const n = 10 / 2;
        const s = "改行は \n このように書く";
        JS;

        $this->assertSame([], JsSyntaxCheck::problems($ok),
            '普通のコードを壊れていると判定しました（誤検知）');
    }

    /** src= の <script> や JSON の <script> は対象外にする。 */
    public function test_it_only_looks_at_real_inline_scripts(): void
    {
        $html = '<script src="/a.js"></script>'
            .'<script type="application/json">{"a":1}</script>'
            .'<script>const a = 1;</script>';

        $this->assertSame(['const a = 1;'], JsSyntaxCheck::extractScripts($html));
    }
}
