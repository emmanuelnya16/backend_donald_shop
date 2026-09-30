<?php

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO de création de compte client.
 * Reçu en JSON depuis le frontend React.
 */
final class UserRegisterDTO
{
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(
        min: 2, max: 100,
        minMessage: 'Le prénom doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères.',
    )]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(min: 2, max: 100)]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'La ville est obligatoire.')]
    #[Assert\Length(max: 100)]
    public string $city = '';

    #[Assert\NotBlank(message: 'Le numéro de téléphone est obligatoire.')]
    #[Assert\Regex(
        pattern: '/^\+?237[0-9]{8,9}$/',
        message: 'Le numéro doit être un numéro camerounais valide. Ex : +237699123456',
    )]
    public string $phone = '';

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
    #[Assert\Length(
        min: 6,
        minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
    )]
    #[Assert\Regex(
        pattern: '/^(?=.*[A-Za-z])(?=.*\d).+$/',
        message: 'Le mot de passe doit contenir au moins une lettre et un chiffre.',
    )]
    public string $password = '';
}