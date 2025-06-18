<?php
namespace SoftcodexProxy;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;

class OidcProxy
{
    private Config $config;
    private GenericProvider $provider;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->provider = new GenericProvider([
            'clientId'                => $config->getClientId(),
            'clientSecret'            => $config->getClientSecret(),
            'redirectUri'             => $config->getRedirectUri(),
            'urlAuthorize'            => $config->getUrlAuthorize(),
            'urlAccessToken'          => $config->getUrlAccessToken(),
            'urlResourceOwnerDetails' => $config->getUrlResourceOwnerDetails(),
            'scopes'                  => $config->getScopes(),
            'scopeSeparator'          => ' ',
        ]);
    }

    public function getAuthorizationUrl(): array
    {
        $scopes = implode(' ', $this->config->getScopes());
        $authorizationUrl = $this->provider->getAuthorizationUrl([
            'scope' => $scopes,
            'response_type' => 'code',
        ]);
        return [
            'url' => $authorizationUrl,
            'state' => $this->provider->getState(),
        ];
    }

    public function handleCallback(string $code, string $state, string $storedState): array
    {
        if ($state !== $storedState) {
            throw new \Exception('Invalid state parameter');
        }

        $accessToken = $this->provider->getAccessToken('authorization_code', [
            'code' => $code,
            'redirect_uri' => $this->config->getRedirectUri(),
            'scope' => implode(' ', $this->config->getScopes()),
        ]);

        if (!$accessToken->getToken()) {
            throw new \Exception('No access_token received from Microsoft');
        }

        $resourceOwner = $this->provider->getResourceOwner($accessToken);
        return [
            'accessToken' => $accessToken->getToken(),
            'refreshToken' => $accessToken->getRefreshToken(),
            'expires' => $accessToken->getExpires(),
            'userData' => $resourceOwner->toArray(),
        ];
    }
}