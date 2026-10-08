<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\LoginOIDC\tests\Unit;

use Piwik\Plugins\LoginOIDC\ProviderConfiguration;

/**
 * @group LoginOIDC
 * @group ProviderConfigurationTest
 * @group Plugins
 */
class ProviderConfigurationTest extends \PHPUnit\Framework\TestCase
{
    public function testDiscoveryUrlFromDomain() : void
    {
        $this->assertEquals(
            "https://idp.example.com/.well-known/openid-configuration",
            ProviderConfiguration::getDiscoveryUrl("idp.example.com")
        );
        $this->assertEquals(
            "https://idp.example.com/.well-known/openid-configuration",
            ProviderConfiguration::getDiscoveryUrl("  idp.example.com  ")
        );
    }

    public function testDiscoveryUrlFromIssuer() : void
    {
        $this->assertEquals(
            "https://idp.example.com/.well-known/openid-configuration",
            ProviderConfiguration::getDiscoveryUrl("https://idp.example.com/")
        );
        $this->assertEquals(
            "https://keycloak.example.com/realms/matomo/.well-known/openid-configuration",
            ProviderConfiguration::getDiscoveryUrl("https://keycloak.example.com/realms/matomo")
        );
        $this->assertEquals(
            "https://authentik.example.com/application/o/matomo/.well-known/openid-configuration",
            ProviderConfiguration::getDiscoveryUrl("https://authentik.example.com/application/o/matomo/")
        );
        $this->assertEquals(
            "http://localhost:8180/realms/dev/.well-known/openid-configuration",
            ProviderConfiguration::getDiscoveryUrl("http://localhost:8180/realms/dev")
        );
    }

    public function testDiscoveryUrlIsKept() : void
    {
        $this->assertEquals(
            "https://idp.example.com/.well-known/openid-configuration",
            ProviderConfiguration::getDiscoveryUrl("https://idp.example.com/.well-known/openid-configuration")
        );
    }
}
