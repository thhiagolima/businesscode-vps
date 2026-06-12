<?php

namespace App\Mail;

use App\Models\MessageDispatch;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class TransactionalMailer
{
    /**
     * Sends a Mailable (or raw text from the dispatch) through Laravel Mail
     * and returns a result array shaped like InfobipService responses:
     *   ['ok' => bool, 'message_id' => ?string, 'error' => ?string]
     */
    public function send(MessageDispatch $dispatch, ?Mailable $mailable = null): array
    {
        try {
            if ($mailable) {
                Mail::to($dispatch->to)->send($mailable);
            } else {
                $subject = $dispatch->subject ?? '(sem assunto)';
                $body    = $dispatch->content;
                // Send the content as HTML so formatting renders in the inbox.
                // EmailController already sanitizes the HTML before persisting.
                Mail::html($body, function ($m) use ($dispatch, $subject) {
                    $m->to($dispatch->to)->subject($subject);
                });
            }
            return ['ok' => true, 'message_id' => 'mail-' . $dispatch->id, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }
}
