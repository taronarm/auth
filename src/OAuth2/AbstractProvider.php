<?php
/**
 * SocialConnect project
 * @author: Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */
declare(strict_types=1);

namespace SocialConnect\OAuth2;

use InvalidArgumentException;
use SocialConnect\OAuth2\Exception\InvalidState;
use SocialConnect\OAuth2\Exception\Unauthorized;
use SocialConnect\OAuth2\Exception\UnknownAuthorization;
use SocialConnect\OAuth2\Exception\UnknownState;
use SocialConnect\Provider\AbstractBaseProvider;
use SocialConnect\Provider\Exception\InvalidAccessToken;
use SocialConnect\Provider\Exception\InvalidResponse;
use SocialConnect\Common\Http\Client\Client;

abstract class AbstractProvider extends AbstractBaseProvider
{
    /**
     * HTTP method for access token request
     *
     * @var string
     */
    protected $requestHttpMethod = Client::POST;

    /**
     * @return string
     */
    abstract public function getAuthorizeUri();

    /**
     * @return string
     */
    abstract public function getRequestTokenUri();

    /**
     * {@inheritdoc}
     */
    public function getAuthUrlParameters(): array
    {
        $parameters = parent::getAuthUrlParameters();

        // special parameters only required for OAuth2
        $parameters['client_id'] = $this->consumer->getKey();
        $parameters['redirect_uri'] = $this->getRedirectUrl();
        $parameters['response_type'] = 'code';

        return $parameters;
    }

    /**
     * 16 bytes / 128 bit / 16 symbols / 32 symbols in hex
     */
    const STATE_BYTES = 16;

	/**
	 * @return string
	 * @throws \Exception
	 */
    protected function generateState()
    {
        return bin2hex(random_bytes(self::STATE_BYTES));
    }

    /**
     * {@inheritdoc}
     */
    public function makeAuthUrl(): string
    {
        $urlParameters = $this->getAuthUrlParameters();

        if (!$this->getBoolOption('stateless', false)) {
            $this->session->set(
                'oauth2_state',
                $urlParameters['state'] = $this->generateState(),
                $this->getName()
            );
        }

        if (count($this->scope) > 0) {
            $urlParameters['scope'] = $this->getScopeInline();
        }

        return $this->getAuthorizeUri() . '?' . http_build_query($urlParameters);
    }

    /**
     * Parse access token from response's $body
     *
     * @param string|bool $body
     * @return AccessToken
     * @throws InvalidAccessToken
     */
    public function parseToken($body)
    {
        if (empty($body)) {
            throw new InvalidAccessToken('Provider response with empty body');
        }

        parse_str($body, $token);

        if (!is_array($token) || !isset($token['access_token'])) {
            throw new InvalidAccessToken('Provider API returned an unexpected response');
        }

        return new AccessToken($token);
    }

    /**
     * @param string $code
     * @return \SocialConnect\Common\Http\Request
     */
    protected function makeAccessTokenRequest($code)
    {
        $parameters = [
            'client_id' => $this->consumer->getKey(),
            'client_secret' => $this->consumer->getSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->getRedirectUrl()
        ];

        return new \SocialConnect\Common\Http\Request(
            $this->getRequestTokenUri(),
            $parameters,
            $this->requestHttpMethod,
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        );
    }

    /**
     * @param string $refresh_token
     * @return \SocialConnect\Common\Http\Request
     */
    protected function makeRefreshTokenRequest($refresh_token)
    {
        $parameters = [
            'client_id' => $this->consumer->getKey(),
            'client_secret' => $this->consumer->getSecret(),
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token',
        ];

        return new \SocialConnect\Common\Http\Request(
            $this->getRequestTokenUri(),
            $parameters,
            $this->requestHttpMethod,
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        );
    }

	/**
	 * @param string $code
	 * @return AccessToken
	 * @throws InvalidAccessToken
	 * @throws InvalidResponse
	 */
    public function getAccessToken($code)
    {
        if (!is_string($code)) {
            throw new InvalidArgumentException('Parameter $code must be a string');
        }

        $response = $this->httpClient->fromRequest(
            $this->makeAccessTokenRequest($code)
        );

        if (!$response->isSuccess()) {
            throw new InvalidResponse(
                'API response with error code',
                $response
            );
        }

        $body = $response->getBody();
        return $this->parseToken($body);
    }

    /**
     * @param string $refreshToken
     * @return AccessToken
	 *
     * @throws InvalidResponse
     * @throws InvalidAccessToken
     */
    public function refreshAccessToken(string $refreshToken)
    {
        $response = $this->httpClient->fromRequest(
            $this->makeRefreshTokenRequest($refreshToken)
        );

        if (!$response->isSuccess()) {
            throw new InvalidResponse(
                'API response with error code',
                $response
            );
        }

        $body = $response->getBody();
        return $this->parseToken($body);
    }

	/**
	 * @param array $parameters
	 * @return AccessToken|\SocialConnect\Provider\AccessTokenInterface
	 * @throws InvalidAccessToken
	 * @throws InvalidResponse
	 * @throws InvalidState
	 * @throws Unauthorized
	 * @throws UnknownAuthorization
	 * @throws UnknownState
	 */
    public function getAccessTokenByRequestParameters(array $parameters)
    {
        if (!$this->getBoolOption('stateless', false)) {
            $state = $this->session->get('oauth2_state', $this->getName());
            if (!$state) {
                throw new UnknownAuthorization();
            }
        }

        if (isset($parameters['error']) && $parameters['error'] === 'access_denied') {
            throw new Unauthorized();
        }

        if (!isset($parameters['state'])) {
            throw new UnknownState();
        }

        if ($state !== $parameters['state']) {
            throw new InvalidState();
        }

        if (!isset($parameters['code'])) {
            throw new Unauthorized('Unknown code');
        }

        return $this->getAccessToken($parameters['code']);
    }
}
