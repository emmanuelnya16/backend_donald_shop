<?php

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO de connexion administrateur.
 * Identifiant : email professionnel.
 */
final class AdminLoginDTO
{
    #[Assert\NotBlank(message: 'L\'email est obligatoire.')]
    #[Assert\Email(message: 'L\'adresse email n\'est pas valide.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
    public string $password = '';
}