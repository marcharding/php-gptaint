<?php

namespace App\Entity;

use App\Repository\IssueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IssueRepository::class)]
class Sample
{
    public const StateUnkown = 99;
    public const StateGood = 0;
    public const StateBad = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $code = null;

    #[ORM\OneToMany(mappedBy: 'issue', targetEntity: AnalysisResult::class)]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $gptResults;

    #[ORM\Column(length: 255)]
    private ?string $file = null;

    #[ORM\Column]
    private ?int $estimatedTokens = null;

    #[ORM\Column]
    private ?int $estimatedTokensUnoptimized = null;

    #[ORM\Column(nullable: true)]
    private ?int $confirmedState = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 255)]
    private ?string $filepath = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(nullable: true)]
    private ?int $CweId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $codeRandomized = null;

    public function __construct()
    {
        $this->gptResults = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    /**
     * @return Collection<int, AnalysisResult>
     */
    public function getGptResults(): Collection
    {
        return $this->gptResults;
    }

    public function getAnalyzerResultsGroupedByAnalyzer(): array
    {
        $groupedResults = [];

        foreach ($this->getGptResults() as $result) {
            $analyzer = $result->getAnalyzer();

            if (!isset($groupedResults[$result->getAnalyzer().'-'.$result->getAnalyzerVersion()])) {
                $groupedResults[$analyzer] = new ArrayCollection(); // Or another collection implementation.
            }

            $groupedResults[$analyzer]->add($result);
        }

        return $groupedResults;
    }

    public function addGptResult(AnalysisResult $gptResult): static
    {
        if (!$this->gptResults->contains($gptResult)) {
            $this->gptResults->add($gptResult);
            $gptResult->setIssue($this);
        }

        return $this;
    }

    public function removeGptResult(AnalysisResult $gptResult): static
    {
        if ($this->gptResults->removeElement($gptResult)) {
            // set the owning side to null (unless already changed)
            if ($gptResult->getIssue() === $this) {
                $gptResult->setIssue(null);
            }
        }

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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

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

    public function setCweId(?int $CweId): static
    {
        $this->CweId = $CweId;

        return $this;
    }

    public function getCodeRandomized(): ?string
    {
        return $this->codeRandomized;
    }

    public function setCodeRandomized(?string $codeRandomized): static
    {
        $this->codeRandomized = $codeRandomized;

        return $this;
    }

    public function getCodeContextCategory(): ?string
    {
        $contextPattern = '/- Context: (?P<category>\w+)/';
        if (preg_match($contextPattern, $this->note, $matches)) {
            $parts = explode('_', $matches['category']);
            return reset($parts);
        }

        return 'undefined';
    }
}
