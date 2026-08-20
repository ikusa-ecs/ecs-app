<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * スタッフへのお知らせメール（アサイン確定／案件公開）。
 *
 * ⚠ 自動では送らない（2026-08-20 baba 決定）。
 *   社員が /assign-notify の画面で相手と文面を確かめて「送信」を押したときだけ送る。
 *
 * 中身はプレーンテキスト。ローカルは MAIL_MAILER=log でログに出るだけ、本番は SES。
 *
 * @param  array<string, string>  $lines  本文に並べる「項目名 => 値」（日付・集合・担当など）
 */
class StaffNotifyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $mailSubject,   // ※ Mailable に既存の $subject があるため別名にしている
        public string $staffName,
        public string $headline,
        public array $lines = [],
        public string $footer = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.staff_notify');
    }
}
