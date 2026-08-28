<x-mail::message>
# Réinitialisation de mot de passe

Une réinitialisation de mot de passe a été demandée pour votre compte (**{{ $identifiant }}**).

Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe. Ce lien est temporaire et expirera.

<x-mail::button :url="$resetUrl">
Choisir un nouveau mot de passe
</x-mail::button>

Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email : votre mot de passe restera inchangé.

Cordialement,<br>
L'équipe Innov Immobilier
</x-mail::message>
