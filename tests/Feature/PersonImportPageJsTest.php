<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 名簿CSV取込の画面のJSが壊れていないことの確認（2026-08-24 baba指摘）。
 *
 * 何が起きたか：文字列の中の改行（\n）を書き換えたときに、それが「本物の改行」に
 * 化けてしまい、JSが構文エラーになった。すると <script> の中身が丸ごと動かなくなり、
 * 「テンプレートをダウンロード」を押しても何も起きない状態になっていた。
 *
 * ＝画面が真っ白にならないので気づきにくい。押しても動かないボタンとして残る。
 * 必要な関数が定義されていること／文字列が途中で改行していないことを見る。
 */
class PersonImportPageJsTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_page_javascript_is_intact(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($admin)->get('/person-import')->assertOk()->getContent();

        // 画面が使う関数がそろっていること。
        foreach (['function piToCsv', 'function piDownloadTemplate', 'function piDownloadIssued',
            'function piImport', 'function piParse'] as $fn) {
            $this->assertStringContainsString($fn, $html, $fn.' が無い');
        }

        // 文字列の途中で改行していないこと（化けた改行が入ると構文エラーになる）。
        // <script> の中の 'シングルクオート' / "ダブルクオート" の数を行ごとに数え、
        // 奇数の行が「正規表現やテンプレートリテラル以外」で出ていないかを見る。
        $lines = preg_split('/\R/', $html);
        foreach ($lines as $i => $line) {
            // 化けたときに実際に現れた形＝行末が「'」で閉じないまま日本語で終わる。
            $this->assertDoesNotMatchRegularExpression(
                '/\?\';?$/',
                $line,
                ($i + 1).'行目に壊れた文字列がある可能性'
            );
        }
    }
}
