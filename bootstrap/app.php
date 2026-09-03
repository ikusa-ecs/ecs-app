<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 権限4段階チェックを 'tier' という名前で使えるようにする（例：middleware('tier:employee')）。
        // 'onboarded' ＝ 初回ログインの初期設定がまだの人を初期設定ページへ誘導する。
        $middleware->alias([
            'tier' => \App\Http\Middleware\EnsureTier::class,
            'onboarded' => \App\Http\Middleware\EnsureOnboarded::class,
            // 'twofa' ＝ ログイン後、メールで届く確認コードを入れるまで業務画面へ進ませない。
            'twofa' => \App\Http\Middleware\EnsureTwoFactor::class,
        ]);

        // ⚠ 表を「コピーして貼り付ける」欄だけは、前後の空白を勝手に落とさない（2026-08-31）。
        //   Laravel は既定で入力の前後の空白を削る。ふだんは親切な機能だが、
        //   スプレッドシートの**選んだ範囲の左端が空のセル**だと1行目がタブで始まるため、
        //   **1行目だけ**そのタブが消えて見出しの列が1つ左にずれる
        //   ＝ 氏名と日付の列が全部ずれて、**1人も読めない／別の人の日に入る**。
        //   エラーも出ないので気づけない。貼り付け欄は元の文字のまま受け取る。
        $middleware->trimStrings(except: ['paste', 'pasted']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ⚠ 画面から裏で送る保存（fetch）は、失敗したときも**必ずJSONで返す**（2026-09-03）。
        //   これが 'api/*' だけだったため、入力の不備でエラーになっても
        //   **ログイン画面へのリダイレクト（302）**が返っていた。
        //   fetch から見ると 302 は「成功」に見える＝**保存できていないのに「保存しました」と出る**。
        //   D決めの都度保存・メモがまさにこれに当たるので、
        //   Accept: application/json（＝裏での保存）のときはJSONで返す。
        //   ⚠ ふつうの画面のフォーム送信は今までどおり（前のページへ戻ってエラー文を出す）。
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
