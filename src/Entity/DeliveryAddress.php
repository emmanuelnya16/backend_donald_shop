<?php

namespace App\Entity;

use App\Repository\DeliveryAddressRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeliveryAddressRepository::class)]
#[ORM\Table(name: 'delivery_addresses')]
class DeliveryAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $lastName;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank]
    private string $phone;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $city;

    #[ORM\Column(type: 'string', length: 150)]
    #[Assert\NotBlank]
    private string $district;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $instructions = null;

    #[ORM\OneToOne(inversedBy: 'deliveryAddress', targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    // ── Helpers ───────────────────────────────────────────────────────────

    public function getFullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    public function getFormattedAddress(): string
    {
        $parts = array_filter([$this->district, $this->street, $this->city]);
        return implode(', ', $parts);
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): static { $this->firstName = $v; return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): static { $this->lastName = $v; return $this; }

    public function getPhone(): string { return $this->phone; }
    public function setPhone(string $v): static { $this->phone = $v; return $this; }

    public function getCity(): string { return $this->city; }
    public function setCity(string $v): static { $this->city = $v; return $this; }

    public function getDistrict(): string { return $this->district; }
    public function setDistrict(string $v): static { $this->district = $v; return $this; }

    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $v): static { $this->street = $v; return $this; }

    public function getInstructions(): ?string { return $this->instructions; }
    public function setInstructions(?string $v): static { $this->instructions = $v; return $this; }

    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $v): static { $this->order = $v; return $this; }
}