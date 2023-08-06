<?php

namespace App\Entity;

use App\Repository\CodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CodeRepository::class)]
class Code
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $directory = null;

    #[ORM\OneToMany(mappedBy: 'code', targetEntity: Issue::class, orphanRemoval: true)]
    private Collection $issues;

    public function __construct()
    {
        $this->issues = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDirectory(): ?string
    {
        return $this->directory;
    }

    public function setDirectory(string $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    /**
     * @return Collection<int, Issue>
     */
    public function getIssues(): Collection
    {
        return $this->issues;
    }

    public function addIssue(Issue $issue): static
    {
        if (!$this->issues->contains($issue)) {
            $this->issues->add($issue);
            $issue->setCode($this);
        }

        return $this;
    }

    public function removeIssue(Issue $issue): static
    {
        if ($this->issues->removeElement($issue)) {
            // set the owning side to null (unless already changed)
            if ($issue->getCode() === $this) {
                $issue->setCode(null);
            }
        }

        return $this;
    }

    public function getIssuesProbabilityGreater80(): int
    {
        $countIssuesOver80 = 0;

        foreach ($this->getIssues() as $issue) {
            $gptProbabilities = [];

            foreach ($issue->getGptResults() as $gptResult) {
                // Assuming 'gpt_probabilit' is a property in $gptResult containing the probability value
                $gptProbabilities[] = $gptResult->getExploitProbability();
            }

            $totalValues = count($gptProbabilities);

            if ($totalValues > 0) {
                $average = array_sum($gptProbabilities) / $totalValues;

                if ($average >= 80) {
                    $countIssuesOver80++;
                }
            }
        }

        return $countIssuesOver80;
    }
}
