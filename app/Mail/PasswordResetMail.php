<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * パスワード再設定（お忘れの方）の案内メール。
 * 本文に「再設定ページへのリンク」を載せる。
 * ローカルは MAIL_MAILER=log でログに出力、本番は AWS SES で送る想定
 * （ログイン用の確認コードメール LoginCodeMail と同じ基盤）。
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public int $expireMinutes,
        public ?string $name = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ECS パスワード再設定のご案内',
        );
    }

    public function content(): Content
    {
        // プレーンテキストのメール（本文にリンクURLを載せるため text で送る）。
        return new Content(
            text: 'emails.password_reset',
        );
    }
}
