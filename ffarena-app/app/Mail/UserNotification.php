<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Plain-text email notification (Phase 11).
 *
 * Sent best-effort alongside every in-app notification. Content is set in
 * the constructor because this Laravel version resolves `subject`/`view`/
 * `viewData` directly (no envelope/content methods).
 */
class UserNotification extends Mailable
{
    public function __construct(
        string $type,
        string $title,
        string $body,
        ?string $link = null,
    ) {
        $this->subject = $title;
        $this->view = 'mail.notification';
        $this->viewData = [
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ];
    }
}
