<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Lien de réinitialisation de mot de passe déclenché par un administrateur.
 * Pointe vers la page publique du front qui consomme le token signé/expirable.
 */
class ResetPasswordLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Réinitialisation de votre mot de passe InResto',
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('inresto.frontend_url'), '/');
        $url = $base.'/reset-password?token='.$this->token.'&email='.urlencode((string) $this->user->email);

        return new Content(
            markdown: 'mail.reset-password-link',
            with: [
                'identifiant' => $this->user->identifiant,
                'resetUrl' => $url,
            ],
        );
    }
}
