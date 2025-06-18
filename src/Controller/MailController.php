<?php

namespace App\Controller;

use App\Entity\ProxyLogs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class MailController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/send-email', name: 'send_email', methods: ['POST'])]
    public function sendEmail(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logError('Malformed JSON: ' . $e->getMessage(), $request, 400, null, null, 0, true);
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Malformed JSON: ' . $e->getMessage()
            ], 400);
        }

        $required = ['subject', 'html', 'from', 'to', 'accessToken'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->logError("Required field is missing: $field", $request, 400, null, null, 0, true);
                return $this->jsonResponse([
                    'status' => 'error',
                    'message' => "Required field is missing: $field"
                ], 400);
            }
        }

        $origin = $data['origin'] ?? null;

        $emailPayload = [
            "message" => [
                "subject" => $data['subject'],
                "body" => [
                    "contentType" => "HTML",
                    "content" => $data['html']
                ],
                "toRecipients" => [
                    [
                        "emailAddress" => [
                            "address" => $data['to']
                        ]
                    ]
                ]
            ],
            "saveToSentItems" => "true"
        ];

        $url = "https://graph.microsoft.com/v1.0/me/sendMail";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($emailPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $data['accessToken'],
                "Content-Type: application/json"
            ]
        ]);

        $start = microtime(true);
        $response = curl_exec($ch);
        $end = microtime(true);

        $durationMs = ($end - $start) * 1000;

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $this->logError('Mail sent successfully.', $request, $httpCode, null, $response, $durationMs, false, $origin);
            return $this->jsonResponse([
                'status' => 'success',
                'message' => 'Mail sent successfully.'
            ]);
        } else {
            $this->logError('Error sending email', $request, $httpCode, $error, $response, $durationMs, true, $origin);
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Error sending email',
                'httpCode' => $httpCode,
                'graphResponse' => json_decode($response, true),
                'curlError' => $error
            ], 500);
        }
    }

    private function logError(string $message, Request $request, int $httpCode = null, string $error = null, string $response = null, float $responseTimeMs = 0, bool $isError = true, ?string $origin = null)
    {
        $log = new ProxyLogs();
        $log->setTimestamp(new \DateTime());
        $log->setClientIp($request->getClientIp());
        $log->setMethod($request->getMethod());
        $log->setUrl($request->getRequestUri());
        $log->setStatusCode($httpCode ?? 400);
        $log->setResponseTime($responseTimeMs);
        $log->setResponseSize(mb_strlen($response ?? '', '8bit'));
        $log->setUserAgent($request->headers->get('User-Agent'));

        if ($isError) {
            $log->setErrorMessage($message);
            $log->setReferer($origin);
        } else {
            $log->setErrorMessage(null);
            $log->setReferer($origin);
        }

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        });

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = json_encode([
                'status' => 'error',
                'message' => 'Error encoding JSON: ' . json_last_error_msg()
            ]);
            $status = 500;
        }

        return new Response($json, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
