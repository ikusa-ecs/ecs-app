<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * お知らせメール（一斉送信）。2026-10-22 baba要望。
 * 件名・本文は Administrator が送るときに入力したもの（送る判断は App\Support\BroadcastMail）。
 * 本番は AWS SES（確認コード・ログイン案内と同じ基盤）。
 */
class BroadcastNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public ?string $name = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.broadcast_notice');
    }
}
