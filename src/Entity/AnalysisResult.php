<?php

namespace App\Entity;

use App\Repository\GptResultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GptResultRepository::class)]
class AnalysisResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $response = null;

    #[ORM\ManyToOne(inversedBy: 'gptResults')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Sample $issue = null;

    #[ORM\Column(nullable: true)]
    private ?int $exploitProbability = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $analysisResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $exploitExample = null;

    #[ORM\Column(length: 255)]
    private ?string $analyzer = null;

    #[ORM\ManyToOne(inversedBy: 'gptResults')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Prompt $prompt = null;

    #[ORM\Column(nullable: true)]
    private ?bool $exploitExampleSuccessful = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $promptMessage = null;

    #[ORM\OneToOne(targetEntity: self::class, cascade: ['persist', 'remove'])]
    private ?self $parentResult = null;

    #[ORM\Column(nullable: true)]
    private ?int $time = null;

    #[ORM\Column(nullable: true)]
    private ?int $promptTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $completionTokens = null;

    #[ORM\Column(length: 255)]
    private ?string $analyzerVersion = null;

    #[ORM\Column]
    private ?int $resultState = null;

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

    public function getIssue(): ?Sample
    {
        return $this->issue;
    }

    public function setIssue(?Sample $issue): static
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

    public function getAnalyzer(): ?string
    {
        return $this->analyzer;
    }

    public function setAnalyzer(string $analyzer): static
    {
        $this->analyzer = $analyzer;

        return $this;
    }

    public function getExploitExample(): ?string
    {
        return 'curl '.str_replace(" ", "%20", trim(str_replace("curl", "", $this->exploitExample)));

    }

    public function setExploitExample(?string $exploitExample): void
    {
        $this->exploitExample = $exploitExample;
    }

    public function getPrompt(): ?Prompt
    {
        return $this->prompt;
    }

    public function setPrompt(?Prompt $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function getMessage(): array
    {
        return [
            [
                'role' => 'user',
                'content' => "{$this->getPromptMessage()} {$this->getIssue()->getCode()}",
            ],
            [
                'role' => 'assistant',
                'content' => "{$this->getResponse()}",
            ],
        ];
    }

    public function isExploitExampleSuccessful(): ?bool
    {
        return $this->exploitExampleSuccessful;
    }

    public function setExploitExampleSuccessful(?bool $exploitExampleSuccessful): static
    {
        $this->exploitExampleSuccessful = $exploitExampleSuccessful;

        return $this;
    }

    public function getPromptMessage(): ?string
    {
        return $this->promptMessage;
    }

    public function setPromptMessage(?string $promptMessage): static
    {
        $this->promptMessage = $promptMessage;

        return $this;
    }

    public function getParentResult(): ?self
    {
        return $this->parentResult;
    }

    public function setParentResult(?self $parentResult): static
    {
        $this->parentResult = $parentResult;

        return $this;
    }

    public function getTime(): ?int
    {
        return $this->time;
    }

    public function setTime(?int $time): static
    {
        $this->time = $time;

        return $this;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function setPromptTokens(?int $promptTokens): static
    {
        $this->promptTokens = $promptTokens;

        return $this;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function setCompletionTokens(?int $completionTokens): static
    {
        $this->completionTokens = $completionTokens;

        return $this;
    }

    public function getAnalyzerVersion(): ?string
    {
        return $this->analyzerVersion;
    }

    public function setAnalyzerVersion(string $analyzerVersion): static
    {
        $this->analyzerVersion = $analyzerVersion;

        return $this;
    }

    public function getResultState(): ?int
    {
        return $this->resultState;
    }

    public function setResultState(int $resultState): static
    {
        $this->resultState = $resultState;

        return $this;
    }
}
