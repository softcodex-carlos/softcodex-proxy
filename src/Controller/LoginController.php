<?php

namespace App\Controller;

use App\Config;
use App\OidcProxy;
use App\Entity\ProxyLogs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class LoginController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/oidc/sso', name: 'oidc_sso', methods: ['POST'])]
    public function sso(Request $request): Response
    {
        $start = microtime(true);

        $clientId = $_ENV['CLIENT_ID'];
        $clientSecret = $_ENV['CLIENT_SECRET'];
        $tenantId = $request->request->get('tenant_id');
        $origin = $request->request->get('origin');
        $allowedEmailDomains = $request->request->get('allowed_email_domains', '');
        $excludedEmailDomains = $request->request->get('excluded_email_domains', '');
        $authUrl = $request->request->get('auth_url');

        $errorMessage = null;
        $statusCode = 200;

        if (!$tenantId || !$origin || !$authUrl) {
            $errorMessage = 'Missing parameters';
            $statusCode = 400;
            $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
            return new Response($errorMessage, $statusCode);
        }

        if (!$clientId || !$clientSecret) {
            $errorMessage = 'Invalid configuration in proxy';
            $statusCode = 500;
            $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
            return new Response($errorMessage, $statusCode);
        }

        if (!filter_var($origin, FILTER_VALIDATE_URL)) {
            $errorMessage = 'Invalid origin URL';
            $statusCode = 400;
            $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
            return new Response($errorMessage, $statusCode);
        }

        if (!filter_var($authUrl, FILTER_VALIDATE_URL) || !str_starts_with($authUrl, 'https://login.microsoftonline.com/')) {
            $errorMessage = 'Invalid authorization URL';
            $statusCode = 400;
            $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
            return new Response($errorMessage, $statusCode);
        }

        $parsedUrl = parse_url($authUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $queryParams['client_id'] = $clientId;
        $finalAuthUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'] . '?' . http_build_query($queryParams);
        $state = $queryParams['state'] ?? '';

        $request->getSession()->set('oauth2_state', $state);
        $request->getSession()->set('oauth2_origin', $origin);
        $request->getSession()->set('client_config', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'tenant_id' => $tenantId,
            'allowed_email_domains' => $allowedEmailDomains,
            'excluded_email_domains' => $excludedEmailDomains,
        ]);

        $end = microtime(true);
        $durationMs = ($end - $start) * 1000;

        $this->logRequest($request, 302, $durationMs, strlen($finalAuthUrl), null, $origin);

        return new RedirectResponse($finalAuthUrl);
    }

    #[Route('/oidc/callback', name: 'oidc_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $start = microtime(true);

        $state = $request->query->get('state');
        $code = $request->query->get('code');
        $error = $request->query->get('error');
        $storedState = $request->getSession()->get('oauth2_state');
        $origin = $request->getSession()->get('oauth2_origin');
        $clientConfig = $request->getSession()->get('client_config');

        $errorMessage = null;
        $statusCode = 200;

        if ($error) {
            $errorMessage = 'OIDC error: ' . $error;
            $statusCode = 400;
            $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
            return new Response($errorMessage, $statusCode);
        }

        if (!$state || !$code || !$origin || !$clientConfig || $state !== $storedState) {
            $errorMessage = 'Invalid or missing session data/state';
            $statusCode = 400;
            $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
            return new Response($errorMessage, $statusCode);
        }

        $redirectUri = $request->getSchemeAndHttpHost() . '/oidc/callback';

        $config = new Config(
            $clientConfig['client_id'],
            $clientConfig['client_secret'],
            $clientConfig['tenant_id'],
            $redirectUri
        );

        $proxy = new OidcProxy($config);

        try {
            $result = $proxy->handleCallback($code, $state, $storedState);

            $email = $result['userData']['mail'] ?? $result['userData']['userPrincipalName'] ?? '';
            $displayName = $result['userData']['displayName'] ?? ucfirst(strtolower(explode('@', $email)[0]));

            if (!$email) {
                $errorMessage = 'Missing email in user data';
                $statusCode = 400;
                $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
                return new Response($errorMessage, $statusCode);
            }

            $emailDomain = strtolower(substr(strrchr($email, '@'), 1));
            $excludedDomains = array_filter(array_map('trim', explode(',', $clientConfig['excluded_email_domains'] ?? '')));
            if (!empty($excludedDomains) && in_array($emailDomain, $excludedDomains)) {
                $errorMessage = 'Email domain not allowed';
                $statusCode = 403;
                $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
                return new Response($errorMessage, $statusCode);
            }

            $allowedDomains = array_filter(array_map('trim', explode(',', $clientConfig['allowed_email_domains'] ?? '')));
            if (!empty($allowedDomains) && !in_array($emailDomain, $allowedDomains)) {
                $errorMessage = 'Email domain not allowed';
                $statusCode = 403;
                $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
                return new Response($errorMessage, $statusCode);
            }

            $query = http_build_query([
                'accessToken' => $result['accessToken'],
                'refreshToken' => $result['refreshToken'],
                'email' => $email,
                'displayName' => $displayName,
            ]);

            $request->getSession()->remove('oauth2_state');
            $request->getSession()->remove('oauth2_origin');
            $request->getSession()->remove('client_config');

            $end = microtime(true);
            $durationMs = ($end - $start) * 1000;

            $this->logRequest($request, 302, $durationMs, strlen($query), null, $origin);

            return new RedirectResponse($origin . '?' . $query);
        } catch (\Throwable $e) {
            $errorMessage = 'OIDC Error: ' . $e->getMessage();
            $statusCode = 500;
            $this->logRequest($request, $statusCode, 0, strlen($errorMessage), $errorMessage, $origin);
            return new Response($errorMessage, $statusCode);
        }
    }

    private function logRequest(Request $request, int $statusCode, float $responseTimeMs, int $responseSize, ?string $errorMessage, ?string $referer): void
    {
        $log = new ProxyLogs();
        $log->setTimestamp(new \DateTime());
        $log->setClientIp($request->getClientIp());
        $log->setMethod($request->getMethod());
        $log->setUrl($request->getRequestUri());
        $log->setStatusCode($statusCode);
        $log->setResponseTime($responseTimeMs);
        $log->setResponseSize($responseSize);
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setReferer($referer);
        $log->setErrorMessage($errorMessage);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
