<?php

namespace App\Controller;

use App\Entity\ProxyLogs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MailController
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
            $this->logError('Malformed JSON: ' . $e->getMessage(), $request);
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Malformed JSON: ' . $e->getMessage()
            ], 400);
        }

        // Check if required fields are present
        $required = ['subject', 'html', 'from', 'to', 'accessToken'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                // Log the missing field error
                $this->logError("Required field is missing: $field", $request);
                return $this->jsonResponse([
                    'status' => 'error',
                    'message' => "Required field is missing: $field"
                ], 400);
            }
        }

        // Send the email (dummy payload for example)
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

        // Call Microsoft Graph API (simulated)
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
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $this->jsonResponse([
                'status' => 'success',
                'message' => 'Mail sent successfully.'
            ]);
        } else {
            // Log the email sending error
            $this->logError('Error sending email', $request, $httpCode, $error, $response);
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Error sending email',
                'httpCode' => $httpCode,
                'graphResponse' => json_decode($response, true),
                'curlError' => $error
            ], 500);
        }
    }

    // Log errors to database
    private function logError(string $message, Request $request, int $httpCode = null, string $error = null, string $response = null)
    {
        $log = new ProxyLogs();
        $log->setTimestamp(new \DateTime());
        $log->setClientIp($request->getClientIp());
        $log->setMethod($request->getMethod());
        $log->setUrl($request->getRequestUri());
        $log->setStatusCode($httpCode ?? 400);  // Default to 400 for validation errors
        $log->setResponseTime(0);  // You can improve this later if needed
        $log->setResponseSize(strlen($response ?? ''));
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setReferer($request->headers->get('Referer'));
        $log->setErrorMessage($message);

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
