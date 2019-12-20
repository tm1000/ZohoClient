<?php

namespace Weble\ZohoClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Cache;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Weble\ZohoClient\Exception\ApiError;
use Weble\ZohoClient\Exception\GrantCodeNotSetException;

class OAuthClient
{
    const OAUTH_GRANT_URL_US = "https://accounts.zoho.com/oauth/v2/auth";
    const OAUTH_GRANT_URL_EU = "https://accounts.zoho.eu/oauth/v2/auth";
    const OAUTH_GRANT_URL_CN = "https://accounts.zoho.cn/oauth/v2/auth";

    const OAUTH_API_URL_US = "https://accounts.zoho.com/oauth/v2/token";
    const OAUTH_API_URL_EU = "https://accounts.zoho.eu/oauth/v2/token";
    const OAUTH_API_URL_CN = "https://accounts.zoho.cn/oauth/v2/token";

    /** @var string */
    protected $region = 'us';

    /** @var Client */
    protected $client;

    /** @var string|null */
    protected $grantCode;

    /** @var string|null */
    protected $redirectUri;

    /** @var array */
    protected $scopes = ['AaaServer.profile.READ'];

    /** @var bool */
    protected $offlineMode = false;

    /** @var string */
    protected $state = 'test';

    /** @var string */
    protected $clientSecret;

    /** @var string */
    protected $clientId;

    /** @var string|null */
    protected $accessToken;

    /** @var \DateTime|null */
    protected $accessTokenExpiration;

    /** @var string|null */
    protected $refreshToken;

    /** @var Cache\CacheItemPoolInterface|null */
    protected $cache;

    /** @var array */
    protected $availableRegions = [];

    public function __construct(string $clientId, string $clientSecret)
    {
        $this->client = new Client();
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    public function availableRegions(): array
    {
        if (count($this->availableRegions) <= 0) {
            try {
                $response = $this->client->get('https://accounts.zoho.com/oauth/serverinfo');
                $data = json_decode($response->getBody());

                if (isset($data['locations'])) {
                    $this->availableRegions = $data['locations'];
                }
            } catch (ClientException $e) {
                $this->availableRegions = [
                    "eu" => "https://accounts.zoho.eu",
                    "au" => "https://accounts.zoho.com.au",
                    "in" => "https://accounts.zoho.in",
                    "us" => "https://accounts.zoho.com"
                ];
            }
        }

        return $this->availableRegions;
    }

    public function setRegion(string $region): self
    {
        $region = strtolower($region);
        $this->region = $region;

        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getBaseUrl(): string
    {
        if (!isset($this->availableRegions()[$this->region])) {
            $keys = array_keys($this->availableRegions());
            return $this->availableRegions()[array_shift($keys)].'/oauth/v2';
        }

        return $this->availableRegions()[$this->region].'/oauth/v2';
    }

    public function getOAuthApiUrl(): string
    {
        return $this->getBaseUrl().'/token';
    }

    public function getOAuthGrantUrl(): string
    {
        return $this->getBaseUrl().'/auth';
    }

    public function setGrantCode(string $grantCode): self
    {
        $this->grantCode = $grantCode;
        return $this;
    }

    public function setState(string $state): self
    {
        $this->state = $state;
        return $this;
    }

    public function setScopes(array $scopes): self
    {
        $this->scopes = $scopes;
        return $this;
    }

    public function onlineMode(): self
    {
        $this->offlineMode = false;
        return $this;
    }

    public function offlineMode(): self
    {
        $this->offlineMode = true;
        return $this;
    }

    public function isOffline(): bool
    {
        return $this->offlineMode;
    }

    public function isOnline(): bool
    {
        return !$this->offlineMode;
    }

    public function setRedirectUri(string $redirectUri): self
    {
        $this->redirectUri = $redirectUri;
        return $this;
    }

    public function useCache(Cache\CacheItemPoolInterface $cacheItemPool): self
    {
        $this->cache = $cacheItemPool;
        return $this;
    }

    public function getHttpClient(): Client
    {
        return $this->client;
    }

    public function getAccessToken(): string
    {
        if ($this->accessTokenExpired() && $this->refreshToken && $this->offlineMode) {
            return $this->refreshAccessToken();
        }

        if ($this->accessToken) {
            return $this->accessToken;
        }

        if (!$this->cache) {
            $this->generateTokens();

            if (!$this->accessToken) {
                throw new ApiError('Cannot generate an access token');
            }

            return $this->accessToken;
        }

        try {
            $cachedAccessToken = $this->cache->getItem('zoho_crm_access_token');

            $value = $cachedAccessToken->get();
            if ($value) {
                return $value;
            }

            $this->generateTokens();

            if (!$this->accessToken) {
                throw new ApiError('Cannot generate an access token');
            }

            return $this->accessToken;

        } catch (InvalidArgumentException $e) {
            $this->generateTokens();

            if (!$this->accessToken) {
                throw new ApiError('Cannot generate an access token');
            }

            return $this->accessToken;
        }
    }

    public function accessTokenExpired(): bool
    {
        if (!$this->accessTokenExpiration) {
            return false;
        }

        return ($this->accessTokenExpiration < new \DateTime());
    }

    public function refreshAccessToken(): string
    {
        if (!$this->getRefreshToken()) {
            throw new ApiError('You need a valid refresh token to refresh an access token');
        }

        $response = $this->client->post($this->getOAuthApiUrl(), [
            'query' => [
                'refresh_token' => $this->getRefreshToken(),
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token'
            ]
        ]);

        $data = json_decode($response->getBody());

        if (!isset($data->access_token)) {
            throw new ApiError(@$data->error);
        }

        $this->setAccessToken($data->access_token, $data->expires_in_sec ?? $data->expires_in ?? 3600);

        return $data->access_token;
    }

    public function getRefreshToken(): string
    {
        if ($this->refreshToken) {
            return $this->refreshToken;
        }

        if (!$this->cache) {
            $this->generateTokens();

            if (!$this->refreshToken) {
                throw new ApiError('Cannot generate a refresh Token');
            }
        }

        try {
            $cachedRefreshToken = $this->cache->getItem('<zoho_crm_refresh_token>');

            $value = $cachedRefreshToken->get();
            if ($value) {
                return $value;
            }

            $this->generateTokens();

            if (!$this->refreshToken) {
                throw new ApiError('Cannot generate a refresh Token');
            }

            return $this->refreshToken;

        } catch (InvalidArgumentException $e) {
            return $this->generateTokens()->getRefreshToken();
        }
    }

    public function revokeRefreshToken(?string $refreshToken = null): self
    {
        if ($refreshToken === null) {
            $refreshToken = $this->refreshToken;
        }

        try {
            $this->client->post($this->getOAuthApiUrl().'/revoke', [
                'query' => [
                    'token' => $refreshToken
                ]
            ]);
        } catch (ClientException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            throw new ApiError($body, $e->getCode());
        }

        return $this;
    }

    public function setAccessToken(string $token, int $expiresInSeconds = 3600): self
    {
        $this->accessToken = $token;
        $this->accessTokenExpiration = (new \DateTime())->add(new \DateInterval('PT'.$expiresInSeconds.'S'));

        if (!$this->cache) {
            return $this;
        }

        try {
            $cachedToken = $this->cache->getItem('zoho_crm_access_token');

            $cachedToken->set($token);
            $cachedToken->expiresAfter($expiresInSeconds);
            $this->cache->save($cachedToken);
        } catch (InvalidArgumentException $e) {

        }
        return $this;
    }

    public function setRefreshToken(string $token, int $expiresInSeconds = 3600): self
    {
        $this->refreshToken = $token;

        if (!$this->cache) {
            return $this;
        }

        try {
            $cachedToken = $this->cache->getItem('zoho_crm_refresh_token');

            $cachedToken->set($token);
            $cachedToken->expiresAfter($expiresInSeconds);
            $this->cache->save($cachedToken);
        } catch (InvalidArgumentException $e) {

        }

        return $this;

    }

    public function generateTokens(): self
    {
        if (!$this->grantCode) {
            throw new GrantCodeNotSetException('You need to pass a grant code to generate an access token.');
        }

        try {
            $response = $this->client->post($this->getOAuthApiUrl(), [
                'query' => [
                    'code' => $this->grantCode,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'state' => $this->state,
                    'grant_type' => 'authorization_code',
                    'scope' => implode(",", $this->scopes),
                    'redirect_uri' => $this->redirectUri
                ]
            ]);
        } catch (ClientException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            throw new ApiError($body, $e->getCode());
        }

        $data = json_decode($response->getBody());

        $this->setAccessToken($data->access_token ?? '', $data->expires_in_sec ?? $data->expires_in ?? 3600);

        if (isset($data->refresh_token)) {
            $this->setRefreshToken($data->refresh_token, $data->expires_in_sec ?? $data->expires_in ?? 3600);
        }

        return $this;
    }

    public function getGrantCodeConsentUrl(): string
    {
        return $this->getOAuthGrantUrl().'?'.http_build_query([
                'access_type' => $this->offlineMode ? 'offline' : 'online',
                'client_id' => $this->clientId,
                'state' => $this->state,
                'redirect_uri' => $this->redirectUri,
                'response_type' => 'code',
                'scope' => implode(',', $this->scopes)
            ]);
    }

    public static function parseGrantTokenFromUrl(UriInterface $uri): ?string
    {
        $query = $uri->getQuery();
        $data = explode('&', $query);

        foreach ($data as &$d) {
            $d = explode("=", $d);
        }

        if (isset($data['code'])) {
            return $data['code'];
        }

        return null;
    }
}