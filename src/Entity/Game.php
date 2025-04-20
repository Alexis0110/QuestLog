<?php

namespace App\Entity;

use App\Repository\GameRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameRepository::class)]
class Game
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable:true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable:true)]
    private ?string $achievements = null;

    #[ORM\Column(type: 'integer', nullable:true)]
    private ?int $achievementsPercent = null;

    #[ORM\Column(type: 'integer', nullable:true)]
    private ?int $steamId = null;
    

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getSteamId(): ?int
    {
        return $this->steamId;
    }

    public function setSteamId(int $steamid): static
    {
        $this->steamId = $steamid;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getAchievements(): ?string
    {
        return $this->achievements;
    }

    public function setAchievements(string $achievements): static
    {
        $this->achievements = $achievements;

        return $this;
    }
    public function getAchievementsPercent(): ?int
    {
        return $this->achievementsPercent;
    }

    public function setAchievementsPercent(int $achievementsPercent): static
    {
        $this->achievementsPercent = $achievementsPercent;

        return $this;
    }
}
