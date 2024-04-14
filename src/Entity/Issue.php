<?php

namespace App\Entity;

use App\Repository\IssueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IssueRepository::class)]
class Issue
{
    public const StateUnkown = 99;
    public const StateGood = 0;
    public const StateBad = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $extractedCodePath = null;

    #[ORM\OneToMany(mappedBy: 'issue', targetEntity: GptResult::class)]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $gptResults;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $psalmResult = null;

    #[ORM\Column(length: 255)]
    private ?string $file = null;

    #[ORM\Column]
    private ?int $estimatedTokens = null;

    #[ORM\Column]
    private ?int $estimatedTokensUnoptimized = null;

    #[ORM\Column(nullable: true)]
    private ?int $confirmedState = null;

    #[ORM\Column(nullable: true)]
    private ?int $psalmState = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(nullable: true)]
    private ?int $snykState = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $snykResult = null;

    #[ORM\Column(length: 255)]
    private ?string $filepath = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?int $CweId = null;

    #[ORM\Column(nullable: true)]
    private ?int $phanState = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $phanResult = null;

    #[ORM\Column(nullable: true)]
    private ?int $phanTime = null;

    #[ORM\Column(nullable: true)]
    private ?int $psalmTime = null;

    #[ORM\Column(nullable: true)]
    private ?int $snykTime = null;

    public function __construct()
    {
        $this->gptResults = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaintId(): ?int
    {
        return $this->taintId;
    }

    public function setTaintId(int $taintId): static
    {
        $this->taintId = $taintId;

        return $this;
    }

    public function getExtractedCodePath(): ?string
    {
        return $this->extractedCodePath;
    }

    public function setExtractedCodePath(string $extractedCodePath): static
    {
        $this->extractedCodePath = $extractedCodePath;

        return $this;
    }

    /**
     * @return Collection<int, GptResult>
     */
    public function getGptResults(): Collection
    {
        return $this->gptResults;
    }

    public function addGptResult(GptResult $gptResult): static
    {
        if (!$this->gptResults->contains($gptResult)) {
            $this->gptResults->add($gptResult);
            $gptResult->setIssue($this);
        }

        return $this;
    }

    public function removeGptResult(GptResult $gptResult): static
    {
        if ($this->gptResults->removeElement($gptResult)) {
            // set the owning side to null (unless already changed)
            if ($gptResult->getIssue() === $this) {
                $gptResult->setIssue(null);
            }
        }

        return $this;
    }

    public function getPsalmResult(): ?string
    {
        return $this->psalmResult;
    }

    public function setPsalmResult(string $psalmResult): static
    {
        $this->psalmResult = $psalmResult;

        return $this;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function setFile(string $file): static
    {
        $this->file = $file;

        return $this;
    }

    public function getEstimatedTokens(): ?int
    {
        return $this->estimatedTokens;
    }

    public function setEstimatedTokens(int $estimatedTokens): static
    {
        $this->estimatedTokens = $estimatedTokens;

        return $this;
    }

    public function getEstimatedTokensUnoptimized(): ?int
    {
        return $this->estimatedTokensUnoptimized;
    }

    public function setEstimatedTokensUnoptimized(int $estimatedTokensUnoptimized): static
    {
        $this->estimatedTokensUnoptimized = $estimatedTokensUnoptimized;

        return $this;
    }

    public function probabilityAverage(): int
    {
        if (count($this->getGptResults()) === 0) {
            return 0;
        }

        $probabilityAverage = 0;
        foreach ($this->getGptResults() as $gptResult) {
            $probabilityAverage += $gptResult->getExploitProbability();
        }

        return $probabilityAverage;
    }

    public function getConfirmedState(): ?int
    {
        return $this->confirmedState;
    }

    public function setConfirmedState(?int $confirmedState): static
    {
        $this->confirmedState = $confirmedState;

        return $this;
    }

    public function getPsalmState(): ?int
    {
        return $this->psalmState;
    }

    public function setPsalmState(?int $psalmState): static
    {
        $this->psalmState = $psalmState;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getSnykState(): ?int
    {
        return $this->snykState;
    }

    public function setSnykState(?int $snykState): static
    {
        $this->snykState = $snykState;

        return $this;
    }

    public function getSnykResult(): ?string
    {
        return $this->snykResult;
    }

    public function setSnykResult(?string $snykResult): static
    {
        $this->snykResult = $snykResult;

        return $this;
    }

    public function getFilepath(): ?string
    {
        return $this->filepath;
    }

    public function setFilepath(string $filepath): static
    {
        $this->filepath = $filepath;

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

    public function getCweId(): ?int
    {
        return $this->CweId;
    }

    public function setCweId(int $CweId): static
    {
        $this->CweId = $CweId;

        return $this;
    }

    public function getPhanState(): ?int
    {
        return $this->phanState;
    }

    public function setPhanState(?int $phanState): static
    {
        $this->phanState = $phanState;

        return $this;
    }

    public function getPhanResult(): ?string
    {
        return $this->phanResult;
    }

    public function setPhanResult(?string $phanResult): static
    {
        $this->phanResult = $phanResult;

        return $this;
    }

    public function getPhanTime(): ?int
    {
        return $this->phanTime;
    }

    public function setPhanTime(?int $phanTime): static
    {
        $this->phanTime = $phanTime;

        return $this;
    }

    public function getPsalmTime(): ?int
    {
        return $this->psalmTime;
    }

    public function setPsalmTime(?int $psalmTime): static
    {
        $this->psalmTime = $psalmTime;

        return $this;
    }

    public function getSnykTime(): ?int
    {
        return $this->snykTime;
    }

    public function setSnykTime(?int $snykTime): static
    {
        $this->snykTime = $snykTime;

        return $this;
    }
}
