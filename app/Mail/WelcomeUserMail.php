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
 * Mail de bienvenue : identifiant + mot de passe provisoire généré, avec
 * invitation à le changer. Envoyé à la création d'un utilisateur quand l'admin
 * n'a pas saisi de mot de passe.
 */
class WelcomeUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $motDePasseProvisoire,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre accès InResto',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.welcome-user',
            with: [
                'identifiant' => $this->user->identifiant,
                'motDePasse' => $this->motDePasseProvisoire,
                'loginUrl' => rtrim((string) config('inresto.frontend_url'), '/').'/login',
            ],
        );
    }
}
