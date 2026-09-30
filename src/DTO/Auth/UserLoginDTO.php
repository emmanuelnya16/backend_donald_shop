<?php

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO de connexion client.
 * Identifiant : numéro de téléphone camerounais.
 */
final class UserLoginDTO
{
    #[Assert\NotBlank(message: 'Le numéro de téléphone est obligatoire.')]
    #[Assert\Regex(
        pattern: '/^\+?237[0-9]{8,9}$/',
        message: 'Format invalide. Ex : +237699123456',
    )]
    public string $phone = '';

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
    public string $password = '';
}