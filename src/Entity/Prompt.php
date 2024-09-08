<?php

namespace App\Entity;

use App\Repository\PromptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromptRepository::class)]
class Prompt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $prompt = null;

    #[ORM\Column(length: 255)]
    private ?string $type = null;

    #[ORM\Column]
    private ?bool $active = null;

    #[ORM\OneToMany(mappedBy: 'prompt', targetEntity: AnalysisResult::class)]
    private Collection $gptResults;

    public function __construct()
    {
        $this->gptResults = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrompt(): ?string
    {
        return $this->prompt;
    }

    public function setPrompt(string $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return Collection<int, AnalysisResult>
     */
    public function getGptResults(): Collection
    {
        return $this->gptResults;
    }

    public function addGptResult(AnalysisResult $gptResult): static
    {
        if (!$this->gptResults->contains($gptResult)) {
            $this->gptResults->add($gptResult);
            $gptResult->setPrompt($this);
        }

        return $this;
    }

    public function removeGptResult(AnalysisResult $gptResult): static
    {
        if ($this->gptResults->removeElement($gptResult)) {
            // set the owning side to null (unless already changed)
            if ($gptResult->getPrompt() === $this) {
                $gptResult->setPrompt(null);
            }
        }

        return $this;
    }
}
