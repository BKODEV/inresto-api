<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Détecte si l'instance dispose d'une configuration d'email exploitable.
 *
 * Single-tenant : chaque déploiement a son propre SMTP. Avant d'envoyer un mot
 * de passe provisoire ou un lien de réinitialisation, on vérifie qu'une adresse
 * expéditrice réelle est configurée — sinon on échoue proprement plutôt que de
 * laisser l'envoi planter.
 */
final class MailConfig
{
    /**
     * Adresse expéditrice « placeholder » fournie par défaut par le framework.
     */
    private const PLACEHOLDER = 'hello@example.com';

    public static function isConfigured(): bool
    {
        $from = config('mail.from.address');

        return is_string($from) && $from !== '' && $from !== self::PLACEHOLDER;
    }
}
