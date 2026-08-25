<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ログイン案内（招待）メール。2026-08-24 baba要望。
 *
 * ⚠ パスワードは本文に載せない。「自分でパスワードを決めるリンク」だけを送る。
 *   メールは受信箱に残り転送もされうるので、パスワードそのものを送らない方針
 *   （送る中身の判断は App\Support\LoginInvite にまとめてある）。
 *
 * ローカルは MAIL_MAILER=log でログに出力、本番は AWS SES で送る
 * （ログインの確認コード LoginCodeMail・パスワード再設定 PasswordResetMail と同じ基盤）。
 */
class LoginInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public ?string $name = null,
        public int $expireDays = 7,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【IKUSA】ECS（アサイン管理システム）のご利用開始について',
        );
    }

    public function content(): Content
    {
        // プレーンテキストのメール（本文にリンクURLを載せるため text で送る）。
        return new Content(
            text: 'emails.login_invite',
        );
    }
}
