<?php

/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\LoginOIDC;

use Exception;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\FrontController;
use Piwik\Mail;
use Piwik\Piwik;
use Piwik\Plugins\Login\Emails\PasswordResetEmail;
use Piwik\Plugins\Login\PasswordVerifier;
use Piwik\Plugins\LoginOIDC\SystemSettings;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Plugins\LoginOIDC\Url;
use Piwik\Request;
use Piwik\Session;

class LoginOIDC extends \Piwik\Plugin
{
    /**
     * Subscribe to Matomo events and assign handlers.
     * https://developer.matomo.org/api-reference/Piwik/Plugin#registerevents
     *
     * @return array
     */
    public function registerEvents() : array
    {
        return array(
            "Session.beforeSessionStart" => "beforeSessionStart",
            "AssetManager.getStylesheetFiles" => "getStylesheetFiles",
            "Template.userSecurity.afterPassword" => "renderLoginOIDCUserSettings",
            "Template.loginNav" => "renderLoginOIDCMod",
            "Template.confirmPasswordContent" => "renderConfirmPasswordMod",
            "Login.logout" => "logoutMod",
            "Login.userRequiresPasswordConfirmation" => "userRequiresPasswordConfirmation",
            "Login.authenticate.successful" => "blockPasswordLogin",
            "API.UsersManager.createAppSpecificTokenAuth" => "blockPasswordTokenCreation",
            "Mail.shouldSend" => "blockPasswordResetMail",
            "Request.dispatch" => "blockPasswordResetConfirmation"
        );
    }

    /**
     * Create RememberMe cookie.
     * @see \Piwik\Plugins\Login::beforeSessionStart
     *
     * @return void
     */
    public function beforeSessionStart() : void
    {
        if (!$this->shouldHandleRememberMe()) {
            return;
        }
        Session::rememberMe(Config::getInstance()->General["login_cookie_expire"]);
    }

    /**
     * Decide if RememberMe cookie should be handled by the plugin.
     * @see \Piwik\Plugins\Login::shouldHandleRememberMe
     *
     * @return bool
     */
    private function shouldHandleRememberMe() : bool
    {
        $module = Request::fromGet()->getStringParameter("module", "");
        $action = Request::fromGet()->getStringParameter("action", "");
        return ($module == "LoginOIDC") && ($action == "callback");
    }

    /**
     * Append additional stylesheets.
     *
     * @param  array  $files
     * @return void
     */
    public function getStylesheetFiles(array &$files)
    {
        $files[] = "plugins/LoginOIDC/stylesheets/loginMod.css";
    }

    /**
     * Register the new tables, so Matomo knows about them.
     *
     * @param array $allTablesInstalled
     */
    public function getTablesInstalled(&$allTablesInstalled)
    {
        $allTablesInstalled[] = Common::prefixTable('loginoidc_provider');
    }

    /**
     * Append custom user settings layout.
     *
     * @param  string  $out
     * @return void
     */
    public function renderLoginOIDCUserSettings(string &$out)
    {
        $content = FrontController::getInstance()->dispatch("LoginOIDC", "userSettings");
        if (!empty($content)) {
            $out .= $content;
        }
    }

    /**
     * Append login oauth button layout.
     *
     * @param  string       $out
     * @param  string|null  $payload
     * @return void
     */
    public function renderLoginOIDCMod(string &$out, string $payload = null)
    {
        if (!empty($payload) && $payload === "bottom") {
            $content = FrontController::getInstance()->dispatch("LoginOIDC", "loginMod");
            if (!empty($content)) {
                $out .= $content;
            }
        }
    }

    /**
     * Append login oauth button layout.
     * The password confirmation modal is not consistent enough to rely on this modification.
     * It is recommended to use the `userRequiresPasswordConfirmation` event instead
     *
     * @param  string       $out
     * @param  string|null  $payload
     * @return void
     */
    public function renderConfirmPasswordMod(string &$out, string $payload = null)
    {
        if (!empty($payload) && $payload === "bottom") {
            $content = FrontController::getInstance()->dispatch("LoginOIDC", "confirmPasswordMod");
            if (!empty($content)) {
                $out .= $content;
            }
        }
    }

    /**
     * Temporarily override logout url to the oidc provider end user session endpoint.
     *
     * @return void
     */
    public function logoutMod()
    {
        $settings = new SystemSettings();
        $endSessionUrl = $settings->endSessionUrl->getValue();
        if (!empty($endSessionUrl) && $_SESSION["loginoidc_auth"]) {
            // make sure we properly unset the plugins session variable
            unset($_SESSION['loginoidc_auth']);
            $endSessionUrl = new Url($endSessionUrl);
            if (isset($_SESSION["loginoidc_idtoken"])) {
                $endSessionUrl->setQueryParameter("id_token_hint", $_SESSION["loginoidc_idtoken"]);
            }
            $originalLogoutUrl = Config::getInstance()->General['login_logout_url'];
            if ($originalLogoutUrl) {
                $endSessionUrl->setQueryParameter("post_logout_redirect_uri", $originalLogoutUrl);
            }
            Config::getInstance()->General['login_logout_url'] = $endSessionUrl->buildString();
        }
    }

    /**
     * Disable password confirmation when user signed in with LoginOIDC.
     * This feature requires Matomo >4.12.0
     *
     * @return void
     */
    public function userRequiresPasswordConfirmation(&$requiresPasswordConfirmation, $login) : void
    {
        $settings = new SystemSettings();
        $disablePasswordConfirmation = $settings->disablePasswordConfirmation->getValue();
        if (!$disablePasswordConfirmation) {
            return;
        }
        // only skip the confirmation if this very user has been authenticated by the remote service recently,
        // using the same time window Matomo grants after a password confirmation
        $verifiedLogin = $_SESSION["loginoidc_verified_login"] ?? null;
        $verifiedAt = $_SESSION["loginoidc_verified_at"] ?? 0;
        if ($verifiedLogin === $login && time() - $verifiedAt < PasswordVerifier::VERIFY_VALID_FOR_MINUTES * 60) {
            $requiresPasswordConfirmation = false;
        }
    }

    /**
     * Refuse password sign-ins of users who have to sign in via the remote service.
     * Triggered after the password has been verified, so it does not reveal linked accounts.
     *
     * @param  string  $login
     * @return void
     */
    public function blockPasswordLogin($login) : void
    {
        // sign-ins through this plugin happen in its callback
        if (Request::fromGet()->getStringParameter("module", "") === "LoginOIDC") {
            return;
        }
        if ($this->isPasswordLoginDisabled((string) $login)) {
            // the login form renders this message as html, keep it free of variable content
            throw new Exception(Piwik::translate("LoginOIDC_ExceptionPasswordLoginDisabled"));
        }
    }

    /**
     * Refuse creating app tokens with the password of users who have to sign in via the remote service.
     * Signed in users only confirm their password there and are not affected.
     *
     * @param  array  $parameters
     * @return void
     */
    public function blockPasswordTokenCreation(&$parameters) : void
    {
        if (!Piwik::isUserIsAnonymous()) {
            return;
        }
        $login = $this->resolveLogin((string) ($parameters["userLogin"] ?? ""));
        if (empty($login) || !$this->isPasswordLoginDisabled($login)) {
            return;
        }
        // verify the password like the API would, so the response does not reveal linked accounts
        $password = (string) ($parameters["passwordConfirmation"] ?? "");
        if (StaticContainer::get(PasswordVerifier::class)->isPasswordCorrect($login, $password)) {
            throw new Exception(Piwik::translate("LoginOIDC_ExceptionPasswordLoginDisabled"));
        }
        throw new Exception(Piwik::translate("UsersManager_CurrentPasswordNotCorrect"));
    }

    /**
     * Do not send password reset emails to users who have to sign in via the remote service.
     * The reset form shows the same message as for any other user, so it does not reveal linked accounts.
     *
     * @param  bool  $shouldSendMail
     * @param  Mail  $mail
     * @return void
     */
    public function blockPasswordResetMail(&$shouldSendMail, Mail $mail) : void
    {
        if (!$shouldSendMail || !($mail instanceof PasswordResetEmail)) {
            return;
        }
        foreach (array_keys($mail->getRecipients()) as $email) {
            $login = $this->resolveLogin((string) $email);
            if (!empty($login) && $this->isPasswordLoginDisabled($login)) {
                $shouldSendMail = false;
            }
        }
    }

    /**
     * Refuse completing a password reset, which has been requested before the password login got disabled.
     *
     * @param  string|null  $module
     * @param  string|null  $action
     * @param  array        $parameters
     * @return void
     */
    public function blockPasswordResetConfirmation(&$module, &$action, &$parameters) : void
    {
        if ($action !== "confirmResetPassword" || $module !== Piwik::getLoginPluginName()) {
            return;
        }
        $login = $this->resolveLogin(Request::fromRequest()->getStringParameter("login", ""));
        if (!empty($login) && $this->isPasswordLoginDisabled($login)) {
            // replace the token, so Matomo rejects it exactly like any invalid token and linked accounts stay undetectable
            $_GET["resetToken"] = $_POST["resetToken"] = "invalid";
        }
    }

    /**
     * Whether the given user is linked to a remote user and must not sign in with a password.
     *
     * @param  string  $login
     * @return bool
     */
    private function isPasswordLoginDisabled(string $login) : bool
    {
        $settings = new SystemSettings();
        if (!$settings->disablePasswordLogin->getValue()) {
            return false;
        }
        $user = (new Model())->getUser($login);
        if (empty($user)) {
            return false;
        }
        // superusers keep their password, when they are not allowed to sign in via the remote service
        if ($settings->disableSuperuser->getValue() && !empty($user["superuser_access"])) {
            return false;
        }
        $sql = "SELECT 1 FROM " . Common::prefixTable("loginoidc_provider") . " WHERE user=? AND provider=?";
        return !empty(Db::fetchOne($sql, array($user["login"], "oidc")));
    }

    /**
     * Find the login of a user given either login or email address.
     *
     * @param  string  $loginOrEmail
     * @return string|null
     */
    private function resolveLogin(string $loginOrEmail) : ?string
    {
        $userModel = new Model();
        $user = $userModel->getUser($loginOrEmail);
        if (empty($user) && Piwik::isValidEmailString($loginOrEmail)) {
            $user = $userModel->getUserByEmail($loginOrEmail);
        }
        return empty($user) ? null : $user["login"];
    }

    /**
     * Extend database.
     *
     * @return void
     */
    public function install()
    {
        // right now there is just one provider but we already add a column to support multiple providers later on
        DbHelper::createTable("loginoidc_provider", "
            `user` VARCHAR( 100 ) NOT NULL,
            `provider_user` VARCHAR( 255 ) NOT NULL,
            `provider` VARCHAR( 255 ) NOT NULL,
            `date_connected` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY ( `provider_user`, `provider` ),
            UNIQUE KEY `user_provider` ( `user`, `provider` ),
            FOREIGN KEY ( `user` ) REFERENCES " . Common::prefixTable("user") . " ( `login` ) ON DELETE CASCADE");
    }

    /**
     * Undo database changes from install.
     *
     * @return void
     */
    public function uninstall()
    {
        Db::dropTables(Common::prefixTable("loginoidc_provider"));
    }
}
