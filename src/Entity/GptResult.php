<?php

namespace App\Entity;

use App\Repository\GptResultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GptResultRepository::class)]
class GptResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $response = null;

    #[ORM\ManyToOne(inversedBy: 'gptResults')]
    private ?Issue $issue = null;

    #[ORM\Column]
    private ?int $exploitProbability = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $analysisResult = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $exploitExample = null;

    #[ORM\Column(length: 255)]
    private ?string $gptVersion = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResponse(): ?string
    {
        return $this->response;
    }

    public function setResponse(string $response): static
    {
        $this->response = $response;

        return $this;
    }

    public function getIssue(): ?Issue
    {
        return $this->issue;
    }

    public function setIssue(?Issue $issue): static
    {
        $this->issue = $issue;

        return $this;
    }

    public function getExploitProbability(): ?int
    {
        return $this->exploitProbability;
    }

    public function setExploitProbability(int $exploitProbability): static
    {
        $this->exploitProbability = $exploitProbability;

        return $this;
    }

    public function getAnalysisResult(): ?string
    {
        return $this->analysisResult;
    }

    public function setAnalysisResult(string $analysisResult): static
    {
        $this->analysisResult = $analysisResult;

        return $this;
    }

    public function getGptVersion(): ?string
    {
        return $this->gptVersion;
    }

    public function setGptVersion(string $gptVersion): static
    {
        $this->gptVersion = $gptVersion;

        return $this;
    }

    public function getExploitExample(): ?string
    {
        return $this->exploitExample;
    }

    public function setExploitExample(?string $exploitExample): void
    {
        $this->exploitExample = $exploitExample;
    }
}
