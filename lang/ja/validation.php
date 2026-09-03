<?php

/*
 * 入力チェック（バリデーション）のメッセージ（日本語）。
 *
 * なぜ必要か：
 *   Laravel は「validation.required」のような合言葉を、この文章集を使って
 *   「◯◯は必ず入力してください。」という日本語に置き換えて画面に出す。
 *   この文章集が無いと、合言葉がそのまま画面に出てしまう
 *   （2026-08-21・テストサーバーで実際に発生。アカウント登録で validation.required と表示された）。
 *
 * 作り：
 *   1) まず土台（Laravel本体）の英語の文章集を読み込む＝ここに書き忘れた項目でも
 *      合言葉が裸で出ることはなく、最低でも英語の文章になる（保険）。
 *   2) そのうえで、よく使うものを日本語で上書きする。
 *   3) 最後の 'attributes' で、項目名を日本語にする（email → メールアドレス など）。
 *      ⚠ 画面に新しい入力欄を足したら、'attributes' にも1行足すこと。
 */

// 1) 土台の英語版（保険）。見つからなければ空でよい。
$base = [];
$fallback = __DIR__ . '/../../vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php';
if (is_file($fallback)) {
    $base = require $fallback;
}

// 2) 日本語の文章（よく使うもの）。
$ja = [
    'accepted'             => ':attributeに同意してください。',
    'active_url'           => ':attributeが正しいURLではありません。',
    'after'                => ':attributeは:dateより後の日付にしてください。',
    'after_or_equal'       => ':attributeは:date以降の日付にしてください。',
    'alpha'                => ':attributeは英字だけで入力してください。',
    'alpha_dash'           => ':attributeは英数字とハイフン・アンダーバーだけで入力してください。',
    'alpha_num'            => ':attributeは英数字だけで入力してください。',
    'array'                => ':attributeの形式が正しくありません。',
    'before'               => ':attributeは:dateより前の日付にしてください。',
    'before_or_equal'      => ':attributeは:date以前の日付にしてください。',
    'between'              => [
        'array'   => ':attributeは:min〜:max個で選んでください。',
        'file'    => ':attributeは:min〜:max KB のファイルにしてください。',
        'numeric' => ':attributeは:min〜:maxの数値にしてください。',
        'string'  => ':attributeは:min〜:max文字で入力してください。',
    ],
    'boolean'              => ':attributeの指定が正しくありません。',
    'confirmed'            => ':attributeが確認用の入力と一致しません。',
    'current_password'     => '現在のパスワードが違います。',
    'date'                 => ':attributeを正しい日付で入力してください。',
    'date_equals'          => ':attributeは:dateと同じ日付にしてください。',
    'date_format'          => ':attributeの日付の形式が正しくありません。',
    'different'            => ':attributeと:otherには違うものを指定してください。',
    'digits'               => ':attributeは:digits桁の数字で入力してください。',
    'digits_between'       => ':attributeは:min〜:max桁の数字で入力してください。',
    'distinct'             => ':attributeが重複しています。',
    'email'                => ':attributeはメールアドレスの形式で入力してください。',
    'ends_with'            => ':attributeは次のいずれかで終わる必要があります：:values',
    'enum'                 => '選択された:attributeは正しくありません。',
    'exists'               => '選択された:attributeは存在しません。',
    'file'                 => ':attributeはファイルを指定してください。',
    'filled'               => ':attributeを入力してください。',
    'gt'                   => [
        'array'   => ':attributeは:value個より多く選んでください。',
        'file'    => ':attributeは:value KB より大きいファイルにしてください。',
        'numeric' => ':attributeは:valueより大きい数値にしてください。',
        'string'  => ':attributeは:value文字より多く入力してください。',
    ],
    'gte'                  => [
        'array'   => ':attributeは:value個以上選んでください。',
        'file'    => ':attributeは:value KB 以上のファイルにしてください。',
        'numeric' => ':attributeは:value以上の数値にしてください。',
        'string'  => ':attributeは:value文字以上で入力してください。',
    ],
    'image'                => ':attributeは画像ファイルを指定してください。',
    'in'                   => '選択された:attributeは正しくありません。',
    'in_array'             => ':attributeが:otherの中にありません。',
    'integer'              => ':attributeは整数で入力してください。',
    'ip'                   => ':attributeはIPアドレスの形式で入力してください。',
    'json'                 => ':attributeの形式（JSON）が正しくありません。',
    'lt'                   => [
        'array'   => ':attributeは:value個より少なく選んでください。',
        'file'    => ':attributeは:value KB より小さいファイルにしてください。',
        'numeric' => ':attributeは:valueより小さい数値にしてください。',
        'string'  => ':attributeは:value文字より少なく入力してください。',
    ],
    'lte'                  => [
        'array'   => ':attributeは:value個以下で選んでください。',
        'file'    => ':attributeは:value KB 以下のファイルにしてください。',
        'numeric' => ':attributeは:value以下の数値にしてください。',
        'string'  => ':attributeは:value文字以下で入力してください。',
    ],
    'max'                  => [
        'array'   => ':attributeは:max個以下で選んでください。',
        'file'    => ':attributeは:max KB 以下のファイルにしてください。',
        'numeric' => ':attributeは:max以下の数値にしてください。',
        'string'  => ':attributeは:max文字以内で入力してください。',
    ],
    'max_digits'           => ':attributeは:max桁以内で入力してください。',
    'mimes'                => ':attributeは次の種類のファイルにしてください：:values',
    'mimetypes'            => ':attributeは次の種類のファイルにしてください：:values',
    'min'                  => [
        'array'   => ':attributeは:min個以上選んでください。',
        'file'    => ':attributeは:min KB 以上のファイルにしてください。',
        'numeric' => ':attributeは:min以上の数値にしてください。',
        'string'  => ':attributeは:min文字以上で入力してください。',
    ],
    'min_digits'           => ':attributeは:min桁以上で入力してください。',
    'not_in'               => '選択された:attributeは正しくありません。',
    'not_regex'            => ':attributeの形式が正しくありません。',
    'numeric'              => ':attributeは数字で入力してください。',
    'password'             => [
        'letters'       => ':attributeには英字を含めてください。',
        'mixed'         => ':attributeには大文字と小文字の両方を含めてください。',
        'numbers'       => ':attributeには数字を含めてください。',
        'symbols'       => ':attributeには記号を含めてください。',
        'uncompromised' => 'この:attributeは流出したパスワードとして知られています。別のものにしてください。',
    ],
    'present'              => ':attributeが指定されていません。',
    'prohibited'           => ':attributeは入力できません。',
    'prohibited_if'        => ':otherが:valueのとき、:attributeは入力できません。',
    'prohibited_unless'    => ':otherが:valuesのとき以外、:attributeは入力できません。',
    'regex'                => ':attributeの形式が正しくありません。',
    'required'             => ':attributeは必ず入力してください。',
    'required_if'          => ':otherが:valueのとき、:attributeは必ず入力してください。',
    'required_unless'      => ':otherが:valuesのとき以外、:attributeは必ず入力してください。',
    'required_with'        => ':valuesを入力したときは、:attributeも必ず入力してください。',
    'required_with_all'    => ':valuesを入力したときは、:attributeも必ず入力してください。',
    'required_without'     => ':valuesが未入力のときは、:attributeを必ず入力してください。',
    'required_without_all' => ':valuesがどれも未入力のときは、:attributeを必ず入力してください。',
    'same'                 => ':attributeと:otherが一致しません。',
    'size'                 => [
        'array'   => ':attributeは:size個選んでください。',
        'file'    => ':attributeは:size KB のファイルにしてください。',
        'numeric' => ':attributeは:sizeにしてください。',
        'string'  => ':attributeは:size文字で入力してください。',
    ],
    'starts_with'          => ':attributeは次のいずれかで始まる必要があります：:values',
    'string'               => ':attributeは文字で入力してください。',
    'timezone'             => ':attributeはタイムゾーンとして正しくありません。',
    'unique'               => 'この:attributeは既に使われています。',
    'uploaded'             => ':attributeのアップロードに失敗しました。',
    'url'                  => ':attributeはURLの形式で入力してください。',
    'uuid'                 => ':attributeの形式が正しくありません。',

    // 個別の項目に個別の文章を出したいとき用（今は未使用）。
    'custom' => [
        'attribute-name' => [
            'rule-name' => 'カスタムメッセージ',
        ],
        // 入社年月日は「年・月・日」の3つのプルダウンで入れる（2026-09-03）。
        // ⚠ 年だけ選んで月を選び忘れたときに、日付として読めない値になって、ここに来る。
        //   「日付の形式が…」では何をすればいいか分からないので、やることを直接書く。
        'hire_date' => [
            'date' => '入社年月日は「年」と「月」の両方を選んでください。',
        ],
    ],

    // 3) 項目名の日本語。ここに無い項目は英語のまま（email など）出る。
    'attributes' => [
        // 共通
        'name'                  => '氏名',
        'email'                 => 'メールアドレス',
        'password'              => 'パスワード',
        'password_confirmation' => 'パスワード（確認）',
        'current_password'      => '現在のパスワード',
        'new_password'          => '新しいパスワード',
        'temp_password'         => '仮パスワード',
        'code'                  => '確認コード',
        'token'                 => 'トークン',
        // アカウント・名簿
        'role'                  => '種別',
        'permission'            => '権限',
        'office'                => '事務所',
        'hire_date'             => '入社年月日',
        'phone'                 => '電話番号',
        'address'               => '住所',
        'prefecture'            => '都道府県',
        'nearest_station'       => '最寄駅',
        'height'                => '身長',
        'shoe_size'             => '靴のサイズ',
        'person_id'             => '対象者',
        'staff_id'              => '対象スタッフ',
        // 案件
        'project_id'            => '案件',
        'project_name'          => '案件名',
        'client_name'           => 'クライアント名',
        'content_names'         => '案件名（コンテンツ）',
        'start_date'            => '開催日',
        'end_date'              => '終了日',
        'date'                  => '日付',
        'required_count'        => '運営人数',
        'scale'                 => '規模',
        'format'                => '実施形態',
        'venue'                 => '会場',
        'status'                => 'ステータス',
        'memo'                  => 'メモ',
        'note'                  => '備考',
        'notice'                => 'お知らせ',
        'deadline'              => '締切',
        // 収支
        'amount'                => '金額',
        'item'                  => '費目',
        // お知らせ・メール
        'subject'               => '件名',
        'title'                 => 'タイトル',
        'body'                  => '本文',
        'message'               => 'メッセージ',
        // 取り込み
        'csv'                   => 'CSVファイル',
        'file'                  => 'ファイル',
    ],
];

return array_replace_recursive($base, $ja);
