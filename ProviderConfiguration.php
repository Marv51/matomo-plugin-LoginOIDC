<?php

/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\LoginOIDC;

use Exception;
use Piwik\Cache;
use Piwik\Http;
use Piwik\Piwik;

/**
 * Endpoints and parameters of the remote service, either read from the provider's
 * OpenID Connect discovery document or configured manually.
 * See: https://openid.net/specs/openid-connect-discovery-1_0.html
 */
class ProviderConfiguration
{
    /**
     * Path of the discovery document relative to the issuer.
     *
     * @var string
     */
    const DISCOVERY_PATH = "/.well-known/openid-configuration";

    /**
     * Seconds the discovered endpoints are cached.
     *
     * @var int
     */
    const DISCOVERY_CACHE_TTL = 3600;

    /**
     * Endpoints which have to be present in the discovery document.
     *
     * @var array
     */
    const REQUIRED_ENDPOINTS = array("authorization_endpoint", "token_endpoint", "userinfo_endpoint");

    /**
     * @var SystemSettings
     */
    private $settings;

    /**
     * The discovered endpoints, once loaded.
     *
     * @var array|null
     */
    private $endpoints;

    /**
     * Constructor.
     *
     * @param SystemSettings  $settings
     */
    public function __construct(SystemSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Whether the endpoints are read from the provider's discovery document.
     *
     * @return bool
     */
    public function usesDiscovery() : bool
    {
        return !empty(trim((string) $this->settings->issuerUrl->getValue()));
    }

    /**
     * Whether all settings required to sign in are present.
     *
     * @return bool
     */
    public function isComplete() : bool
    {
        if (empty($this->settings->clientId->getValue()) || empty($this->settings->clientSecret->getValue())) {
            return false;
        }
        return $this->usesDiscovery()
            || (!empty($this->settings->authorizeUrl->getValue())
                && !empty($this->settings->tokenUrl->getValue())
                && !empty($this->settings->userinfoUrl->getValue()));
    }

    /**
     * @return string
     */
    public function getAuthorizeUrl() : string
    {
        return $this->usesDiscovery()
            ? $this->getEndpoints()["authorization_endpoint"]
            : $this->settings->authorizeUrl->getValue();
    }

    /**
     * @return string
     */
    public function getTokenUrl() : string
    {
        return $this->usesDiscovery()
            ? $this->getEndpoints()["token_endpoint"]
            : $this->settings->tokenUrl->getValue();
    }

    /**
     * @return string
     */
    public function getUserinfoUrl() : string
    {
        return $this->usesDiscovery()
            ? $this->getEndpoints()["userinfo_endpoint"]
            : $this->settings->userinfoUrl->getValue();
    }

    /**
     * The end session endpoint, empty if the provider does not support RP-initiated logout.
     *
     * @return string
     */
    public function getEndSessionUrl() : string
    {
        return $this->usesDiscovery()
            ? ($this->getEndpoints()["end_session_endpoint"] ?? "")
            : (string) $this->settings->endSessionUrl->getValue();
    }

    /**
     * Name of the unique user id in the userinfo response, OpenID Connect always provides it as `sub`.
     *
     * @return string
     */
    public function getUserinfoId() : string
    {
        return $this->usesDiscovery() ? "sub" : $this->settings->userinfoId->getValue();
    }

    /**
     * Scopes to request, OpenID Connect requests always have to include `openid`.
     *
     * @return string
     */
    public function getScope() : string
    {
        $scopes = preg_split("/\s+/", trim((string) $this->settings->scope->getValue()), -1, PREG_SPLIT_NO_EMPTY);
        if ($this->usesDiscovery()) {
            if (empty($scopes)) {
                $scopes = array("openid", "email");
            } elseif (!in_array("openid", $scopes, true)) {
                array_unshift($scopes, "openid");
            }
        }
        return implode(" ", $scopes);
    }

    /**
     * Build the discovery url from a domain, an issuer url or the discovery url itself.
     *
     * @param  string  $issuer  e.g. idp.example.com or https://idp.example.com/realms/matomo
     * @return string
     */
    public static function getDiscoveryUrl(string $issuer) : string
    {
        $url = trim($issuer);
        if (!preg_match("#^https?://#i", $url)) {
            $url = "https://" . $url;
        }
        $url = rtrim($url, "/");
        if (substr($url, -strlen(self::DISCOVERY_PATH)) !== self::DISCOVERY_PATH) {
            $url .= self::DISCOVERY_PATH;
        }
        return $url;
    }

    /**
     * Load the endpoints from the discovery document of the given issuer and cache them.
     *
     * @param  string  $issuer
     * @return array
     * @throws Exception if the discovery document cannot be loaded or lacks required endpoints
     */
    public static function refreshDiscovery(string $issuer) : array
    {
        $discoveryUrl = self::getDiscoveryUrl($issuer);
        $endpoints = self::fetchEndpoints($discoveryUrl);
        Cache::getLazyCache()->save(self::getCacheId($discoveryUrl), $endpoints, self::DISCOVERY_CACHE_TTL);
        return $endpoints;
    }

    /**
     * The discovered endpoints, cached for a while.
     *
     * @return array
     */
    private function getEndpoints() : array
    {
        if ($this->endpoints === null) {
            $issuer = (string) $this->settings->issuerUrl->getValue();
            $endpoints = Cache::getLazyCache()->fetch(self::getCacheId(self::getDiscoveryUrl($issuer)));
            $this->endpoints = is_array($endpoints) ? $endpoints : self::refreshDiscovery($issuer);
        }
        return $this->endpoints;
    }

    /**
     * Fetch the discovery document and extract the endpoints.
     *
     * @param  string  $discoveryUrl
     * @return array
     * @throws Exception
     */
    private static function fetchEndpoints(string $discoveryUrl) : array
    {
        try {
            $response = Http::sendHttpRequest($discoveryUrl, 10, null, null, 0, false, false, true);
        } catch (Exception $e) {
            throw self::discoveryFailed($discoveryUrl, $e->getMessage());
        }
        if (empty($response) || (int) $response["status"] !== 200) {
            throw self::discoveryFailed($discoveryUrl, "HTTP " . ($response["status"] ?? "error"));
        }
        $document = json_decode($response["data"] ?? "", true);
        if (!is_array($document)) {
            throw self::discoveryFailed($discoveryUrl, "no JSON document");
        }

        $endpoints = array();
        foreach (array_merge(self::REQUIRED_ENDPOINTS, array("end_session_endpoint")) as $name) {
            $value = $document[$name] ?? null;
            if (is_string($value) && preg_match("#^https?://#i", $value)) {
                $endpoints[$name] = $value;
            } elseif (in_array($name, self::REQUIRED_ENDPOINTS, true)) {
                throw self::discoveryFailed($discoveryUrl, "missing " . $name);
            }
        }
        return $endpoints;
    }

    /**
     * @param  string  $discoveryUrl
     * @param  string  $reason
     * @return Exception
     */
    private static function discoveryFailed(string $discoveryUrl, string $reason) : Exception
    {
        return new Exception(Piwik::translate("LoginOIDC_ExceptionDiscoveryFailed", array($discoveryUrl, $reason)));
    }

    /**
     * @param  string  $discoveryUrl
     * @return string
     */
    private static function getCacheId(string $discoveryUrl) : string
    {
        return "LoginOIDC_discovery_" . md5($discoveryUrl);
    }
}
