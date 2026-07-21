<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ログイン時の2段階認証コード（メールで送る6桁）。
 * ローカルは MAIL_MAILER=log でログに出力、本番は AWS SES で送る想定。
 */
class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public ?string $name = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ECS ログイン用の確認コード',
        );
    }

    public function content(): Content
    {
        // プレーンテキストのメール（HTMLだと改行がつぶれて1行に見えるため text で送る）。
        return new Content(
            text: 'emails.login_code',
        );
    }
}
