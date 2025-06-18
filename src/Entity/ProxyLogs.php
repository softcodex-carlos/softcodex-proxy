<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: 'App\Repository\ProxyLogsRepository')]
#[ORM\Table(name: 'proxy_logs')]
class ProxyLogs
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $timestamp;

    #[ORM\Column(type: 'string', length: 45)]
    private string $clientIp;

    #[ORM\Column(type: 'string', length: 10)]
    private string $method;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'integer')]
    private int $statusCode;

    #[ORM\Column(type: 'float')]
    private float $responseTime;

    #[ORM\Column(type: 'integer')]
    private int $responseSize;

    #[ORM\Column(type: 'text')]
    private string $userAgent;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referer;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage;

    // Constructor para inicializar el timestamp
    public function __construct()
    {
        $this->timestamp = new \DateTime(); // Por defecto, se establece la fecha y hora actual
    }

    // Getters y Setters...

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTimestamp(): DateTimeInterface
    {
        return $this->timestamp;
    }

    public function setTimestamp(DateTimeInterface $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    public function setClientIp(string $clientIp): self
    {
        $this->clientIp = $clientIp;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    public function getResponseTime(): float
    {
        return $this->responseTime;
    }

    public function setResponseTime(float $responseTime): self
    {
        $this->responseTime = $responseTime;
        return $this;
    }

    public function getResponseSize(): int
    {
        return $this->responseSize;
    }

    public function setResponseSize(int $responseSize): self
    {
        $this->responseSize = $responseSize;
        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function setReferer(?string $referer): self
    {
        $this->referer = $referer;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }
}
