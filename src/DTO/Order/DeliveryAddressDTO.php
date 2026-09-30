<?php

namespace App\DTO\Order;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * L'adresse de livraison envoyée par React.
 */
final class DeliveryAddressDTO
{
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 100)]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'Le numéro de téléphone est obligatoire.')]
    #[Assert\Regex(
        pattern: '/^\+?237[0-9]{8,9}$/',
        message: 'Numéro camerounais invalide. Ex : +237699123456',
    )]
    public string $phone = '';

    #[Assert\NotBlank(message: 'La ville est obligatoire.')]
    #[Assert\Length(max: 100)]
    public string $city = '';

    #[Assert\NotBlank(message: 'Le quartier est obligatoire.')]
    #[Assert\Length(max: 150)]
    public string $district = '';

    #[Assert\Length(max: 255)]
    public ?string $street = null;

    public ?string $instructions = null;
}