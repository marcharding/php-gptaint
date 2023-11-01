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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $taintId = null;

    /**
     * @ORM\ManyToOne(targetEntity="Issue")
     *
     * @ORM\JoinColumn(name="issue_id", referencedColumnName="id")
     */
    private $issue;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $extractedCodePath = null;

    #[ORM\ManyToOne(inversedBy: 'issues')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Code $code = null;

    #[ORM\OneToMany(mappedBy: 'issue', targetEntity: GptResult::class)]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $gptResults;

    #[ORM\Column(length: 255)]
    private ?string $type = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $psalmResult = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private ?string $file = null;

    #[ORM\Column]
    private ?int $estimatedTokens = null;

    #[ORM\Column]
    private ?int $estimatedTokensUnoptimized = null;

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

    public function getCode(): ?Code
    {
        return $this->code;
    }

    public function setCode(?Code $code): static
    {
        $this->code = $code;

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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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
        if (!$this->getGptResults()) {
            return 0;
        }

        $probabilityAverage = 0;
        foreach ($this->getGptResults() as $gptResult) {
            $probabilityAverage += $gptResult->getExploitProbability();
        }

        return $probabilityAverage / count($this->getGptResults());
    }
}
