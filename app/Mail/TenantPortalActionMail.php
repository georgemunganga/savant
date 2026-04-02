<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantPortalActionMail extends Mailable
{
    use Queueable, SerializesModels;

    public $content;
    public $subjectLine;

    public function __construct(array $content)
    {
        $this->content = $content;
        $this->subjectLine = $content['subject'];
    }

    public function build()
    {
        return $this->view('mail.tenant-portal-action')
            ->subject($this->subjectLine)
            ->with('content', $this->content);
    }
}
