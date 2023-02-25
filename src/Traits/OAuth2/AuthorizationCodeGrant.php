<?php

declare(strict_types=1);

namespace Saloon\Traits\OAuth2;

use DateTimeImmutable;
use Saloon\Helpers\Str;
use Saloon\Helpers\Date;
use InvalidArgumentException;
use Saloon\Helpers\URLHelper;
use Saloon\Contracts\Response;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\OAuth2\GetUserRequest;
use Saloon\Contracts\OAuthAuthenticator;
use Saloon\Exceptions\InvalidStateException;
use Saloon\Http\OAuth2\GetAccessTokenRequest;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use Saloon\Http\OAuth2\GetRefreshTokenRequest;

trait AuthorizationCodeGrant
{
    /**
     * The OAuth2 Config
     *
     * @var \Saloon\Helpers\OAuth2\OAuthConfig
     */
    protected OAuthConfig $oauthConfig;

    /**
     * The state generated by the getAuthorizationUrl method.
     *
     * @var string|null
     */
    protected ?string $state = null;

    /**
     * Manage the OAuth2 config
     *
     * @return \Saloon\Helpers\OAuth2\OAuthConfig
     */
    public function oauthConfig(): OAuthConfig
    {
        return $this->oauthConfig ??= $this->defaultOauthConfig();
    }

    /**
     * Define the default Oauth 2 Config.
     *
     * @return \Saloon\Helpers\OAuth2\OAuthConfig
     */
    protected function defaultOauthConfig(): OAuthConfig
    {
        return OAuthConfig::make();
    }

    /**
     * Get the Authorization URL.
     *
     * @param array<string> $scopes
     * @param string|null $state
     * @param string $scopeSeparator
     * @return string
     * @throws \Saloon\Exceptions\OAuthConfigValidationException
     */
    public function getAuthorizationUrl(array $scopes = [], string $state = null, string $scopeSeparator = ' '): string
    {
        $config = $this->oauthConfig();

        $config->validate();

        $clientId = $config->getClientId();
        $redirectUri = $config->getRedirectUri();
        $defaultScopes = $config->getDefaultScopes();

        $this->state = $state ?? Str::random(32);

        $queryParameters = [
            'response_type' => 'code',
            'scope' => implode($scopeSeparator, array_merge($defaultScopes, $scopes)),
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $this->state,
        ];

        $query = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);
        $query = trim($query, '?&');

        $url = URLHelper::join($this->resolveBaseUrl(), $config->getAuthorizeEndpoint());

        $glue = str_contains($url, '?') ? '&' : '?';

        return $url . $glue . $query;
    }

    /**
     * Get the access token.
     *
     * @template TRequest of \Saloon\Contracts\Request
     *
     * @param string $code
     * @param string|null $state
     * @param string|null $expectedState
     * @param bool $returnResponse
     * @param callable(TRequest): (void)|null $requestModifier
     * @return \Saloon\Contracts\OAuthAuthenticator|\Saloon\Contracts\Response
     * @throws \ReflectionException
     * @throws \Saloon\Exceptions\InvalidResponseClassException
     * @throws \Saloon\Exceptions\InvalidStateException
     * @throws \Saloon\Exceptions\OAuthConfigValidationException
     * @throws \Saloon\Exceptions\PendingRequestException
     */
    public function getAccessToken(string $code, string $state = null, string $expectedState = null, bool $returnResponse = false, ?callable $requestModifier = null): OAuthAuthenticator|Response
    {
        $this->oauthConfig()->validate();

        if (! empty($state) && ! empty($expectedState) && $state !== $expectedState) {
            throw new InvalidStateException;
        }

        $request = new GetAccessTokenRequest($code, $this->oauthConfig());

        if (is_callable($requestModifier)) {
            $requestModifier($request);
        }

        $response = $this->send($request);

        if ($returnResponse === true) {
            return $response;
        }

        $response->throw();

        return $this->createOAuthAuthenticatorFromResponse($response);
    }

    /**
     * Refresh the access token.
     *
     * @template TRequest of \Saloon\Contracts\Request
     *
     * @param \Saloon\Contracts\OAuthAuthenticator|string $refreshToken
     * @param bool $returnResponse
     * @param callable(TRequest): (void)|null $requestModifier
     * @return \Saloon\Contracts\OAuthAuthenticator|\Saloon\Contracts\Response
     * @throws \ReflectionException
     * @throws \Saloon\Exceptions\InvalidResponseClassException
     * @throws \Saloon\Exceptions\OAuthConfigValidationException
     * @throws \Saloon\Exceptions\PendingRequestException
     */
    public function refreshAccessToken(OAuthAuthenticator|string $refreshToken, bool $returnResponse = false, ?callable $requestModifier = null): OAuthAuthenticator|Response
    {
        $this->oauthConfig()->validate();

        if ($refreshToken instanceof OAuthAuthenticator) {
            if ($refreshToken->isNotRefreshable()) {
                throw new InvalidArgumentException('The provided OAuthAuthenticator does not contain a refresh token.');
            }

            $refreshToken = $refreshToken->getRefreshToken();
        }

        $request = new GetRefreshTokenRequest($this->oauthConfig(), $refreshToken);

        if (is_callable($requestModifier)) {
            $requestModifier($request);
        }

        $response = $this->send($request);

        if ($returnResponse === true) {
            return $response;
        }

        $response->throw();

        return $this->createOAuthAuthenticatorFromResponse($response, $refreshToken);
    }

    /**
     * Create the OAuthAuthenticator from a response.
     *
     * @param \Saloon\Contracts\Response $response
     * @param string|null $fallbackRefreshToken
     * @return \Saloon\Contracts\OAuthAuthenticator
     */
    protected function createOAuthAuthenticatorFromResponse(Response $response, string $fallbackRefreshToken = null): OAuthAuthenticator
    {
        $responseData = $response->object();

        $accessToken = $responseData->access_token;
        $refreshToken = $responseData->refresh_token ?? $fallbackRefreshToken;
        $expiresAt = isset($responseData->expires_in) ? Date::now()->addSeconds($responseData->expires_in)->toDateTime() : null;

        return $this->createOAuthAuthenticator($accessToken, $refreshToken, $expiresAt);
    }

    /**
     * Create the authenticator.
     *
     * @param string $accessToken
     * @param string $refreshToken
     * @param DateTimeImmutable|null $expiresAt
     * @return OAuthAuthenticator
     */
    protected function createOAuthAuthenticator(string $accessToken, string $refreshToken, ?DateTimeImmutable $expiresAt = null): OAuthAuthenticator
    {
        return new AccessTokenAuthenticator($accessToken, $refreshToken, $expiresAt);
    }

    /**
     * Get the authenticated user.
     *
     * @param \Saloon\Contracts\OAuthAuthenticator $oauthAuthenticator
     * @return \Saloon\Contracts\Response
     * @throws \ReflectionException
     * @throws \Saloon\Exceptions\InvalidResponseClassException
     * @throws \Saloon\Exceptions\PendingRequestException
     */
    public function getUser(OAuthAuthenticator $oauthAuthenticator): Response
    {
        return $this->send(
            GetUserRequest::make($this->oauthConfig())->authenticate($oauthAuthenticator)
        );
    }

    /**
     * Get the state that was generated in the getAuthorizationUrl() method.
     *
     * @return string|null
     */
    public function getState(): ?string
    {
        return $this->state;
    }
}
