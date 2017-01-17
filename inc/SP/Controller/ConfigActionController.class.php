<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      http://syspass.org
 * @copyright 2012-2017, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Controller;

use SP\Account\Account;
use SP\Account\AccountHistory;
use SP\Config\Config;
use SP\Config\ConfigDB;
use SP\Core\ActionsInterface;
use SP\Core\Backup;
use SP\Core\Crypt;
use SP\Core\CryptMasterPass;
use SP\Core\Exceptions\SPException;
use SP\Core\Messages\LogMessage;
use SP\Core\Session;
use SP\Core\XmlExport;
use SP\Http\Request;
use SP\Import\Import;
use SP\Import\ImportParams;
use SP\Log\Email;
use SP\Log\Log;
use SP\Mgmt\CustomFields\CustomFieldsUtil;
use SP\Mgmt\Users\UserPass;
use SP\Util\Checks;
use SP\Util\Json;
use SP\Util\Util;

/**
 * Class ConfigActionController
 *
 * @package SP\Controller
 */
class ConfigActionController implements ItemControllerInterface
{
    use RequestControllerTrait;

    /**
     * ConfigActionController constructor.
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Realizar la acción solicitada en la la petición HTTP
     *
     * @throws \SP\Core\Exceptions\SPException
     */
    public function doAction()
    {
        $this->LogMessage = new LogMessage();

        try {
            switch ($this->actionId) {
                case ActionsInterface::ACTION_CFG_GENERAL:
                    $this->generalAction();
                    break;
                case ActionsInterface::ACTION_CFG_WIKI:
                    $this->wikiAction();
                    break;
                case ActionsInterface::ACTION_CFG_LDAP:
                    $this->ldapAction();
                    break;
                case ActionsInterface::ACTION_CFG_MAIL:
                    $this->mailAction();
                    break;
                case ActionsInterface::ACTION_CFG_ENCRYPTION:
                    $this->masterPassAction();
                    break;
                case ActionsInterface::ACTION_CFG_ENCRYPTION_TEMPPASS:
                    $this->tempMasterPassAction();
                    break;
                case ActionsInterface::ACTION_CFG_IMPORT:
                    $this->importAction();
                    break;
                case ActionsInterface::ACTION_CFG_EXPORT:
                    $this->exportAction();
                    break;
                case ActionsInterface::ACTION_CFG_BACKUP:
                    $this->backupAction();
                    break;
                default:
                    $this->invalidAction();
            }
        } catch (\Exception $e) {
            $this->JsonResponse->setDescription($e->getMessage());
        }

        if ($this->LogMessage->getAction() !== null) {
            $Log = new Log($this->LogMessage);
            $Log->writeLog();

            $this->JsonResponse->setDescription($this->LogMessage->getHtmlDescription());
        }

        Json::returnJson($this->JsonResponse);
    }

    /**
     * Accion para opciones configuración general
     *
     * @throws \SP\Core\Exceptions\SPException
     */
    protected function generalAction()
    {
        $Config = Session::getConfig();

        // General
        $siteLang = Request::analyze('sitelang');
        $siteTheme = Request::analyze('sitetheme', 'material-blue');
        $sessionTimeout = Request::analyze('session_timeout', 300);
        $httpsEnabled = Request::analyze('https_enabled', false, false, true);
        $debugEnabled = Request::analyze('debug', false, false, true);
        $maintenanceEnabled = Request::analyze('maintenance', false, false, true);
        $checkUpdatesEnabled = Request::analyze('updates', false, false, true);
        $checkNoticesEnabled = Request::analyze('notices', false, false, true);

        $Config->setSiteLang($siteLang);
        $Config->setSiteTheme($siteTheme);
        $Config->setSessionTimeout($sessionTimeout);
        $Config->setHttpsEnabled($httpsEnabled);
        $Config->setDebug($debugEnabled);
        $Config->setMaintenance($maintenanceEnabled);
        $Config->setCheckUpdates($checkUpdatesEnabled);
        $Config->setChecknotices($checkNoticesEnabled);

        // Events
        $logEnabled = Request::analyze('log_enabled', false, false, true);
        $syslogEnabled = Request::analyze('syslog_enabled', false, false, true);
        $remoteSyslogEnabled = Request::analyze('remotesyslog_enabled', false, false, true);
        $syslogServer = Request::analyze('remotesyslog_server');
        $syslogPort = Request::analyze('remotesyslog_port', 0);

        $Config->setLogEnabled($logEnabled);
        $Config->setSyslogEnabled($syslogEnabled);

        if ($remoteSyslogEnabled && (!$syslogServer || !$syslogPort)) {
            $this->JsonResponse->setDescription(__('Faltan parámetros de syslog remoto', false));
            return;
        } elseif ($remoteSyslogEnabled) {
            $Config->setSyslogRemoteEnabled($remoteSyslogEnabled);
            $Config->setSyslogServer($syslogServer);
            $Config->setSyslogPort($syslogPort);
        } elseif ($Config->isSyslogEnabled()) {
            $Config->setSyslogRemoteEnabled(false);

            $this->LogMessage->addDescription(__('Syslog remoto deshabilitado', false));
        }

        // Accounts
        $globalSearchEnabled = Request::analyze('globalsearch', false, false, true);
        $accountPassToImageEnabled = Request::analyze('account_passtoimage', false, false, true);
        $accountLinkEnabled = Request::analyze('account_link', false, false, true);
        $accountCount = Request::analyze('account_count', 10);
        $resultsAsCardsEnabled = Request::analyze('resultsascards', false, false, true);

        $Config->setGlobalSearch($globalSearchEnabled);
        $Config->setAccountPassToImage($accountPassToImageEnabled);
        $Config->setAccountLink($accountLinkEnabled);
        $Config->setAccountCount($accountCount);
        $Config->setResultsAsCards($resultsAsCardsEnabled);

        // Files
        $filesEnabled = Request::analyze('files_enabled', false, false, true);
        $filesAllowedSize = Request::analyze('files_allowed_size', 1024);
        $filesAllowedExts = Request::analyze('files_allowed_exts');

        if ($filesEnabled && $filesAllowedSize >= 16384) {
            $this->JsonResponse->setDescription(__('El tamaño máximo por archivo es de 16MB', false));
            return;
        }

        if (!empty($filesAllowedExts)) {
            $exts = explode(',', $filesAllowedExts);
            array_walk($exts, function (&$value) {
                if (preg_match('/[^a-z0-9_-]+/i', $value)) {
                    $this->JsonResponse->setDescription(sprintf('%s: %s', __('Extensión no permitida'), $value));
                    Json::returnJson($this->JsonResponse);
                }
            });
            $Config->setFilesAllowedExts($exts);
        } else {
            $Config->setFilesAllowedExts([]);
        }

        $Config->setFilesEnabled($filesEnabled);
        $Config->setFilesAllowedSize($filesAllowedSize);

        // Public Links
        $pubLinksEnabled = Request::analyze('publinks_enabled', false, false, true);
        $pubLinksImageEnabled = Request::analyze('publinks_image_enabled', false, false, true);
        $pubLinksMaxTime = Request::analyze('publinks_maxtime', 10);
        $pubLinksMaxViews = Request::analyze('publinks_maxviews', 3);

        $Config->setPublinksEnabled($pubLinksEnabled);
        $Config->setPublinksImageEnabled($pubLinksImageEnabled);
        $Config->setPublinksMaxTime($pubLinksMaxTime * 60);
        $Config->setPublinksMaxViews($pubLinksMaxViews);

        // Proxy
        $proxyEnabled = Request::analyze('proxy_enabled', false, false, true);
        $proxyServer = Request::analyze('proxy_server');
        $proxyPort = Request::analyze('proxy_port', 0);
        $proxyUser = Request::analyze('proxy_user');
        $proxyPass = Request::analyzeEncrypted('proxy_pass');


        // Valores para Proxy
        if ($proxyEnabled && (!$proxyServer || !$proxyPort)) {
            $this->JsonResponse->setDescription(__('Faltan parámetros de Proxy', false));
            return;
        } elseif ($proxyEnabled) {
            $Config->setProxyEnabled(true);
            $Config->setProxyServer($proxyServer);
            $Config->setProxyPort($proxyPort);
            $Config->setProxyUser($proxyUser);
            $Config->setProxyPass($proxyPass);

            $this->LogMessage->addDescription(__('Proxy habiltado', false));
        } elseif ($Config->isProxyEnabled()) {
            $Config->setProxyEnabled(false);

            $this->LogMessage->addDescription(__('Proxy deshabilitado', false));
        }

        $this->LogMessage->addDetails(__('Sección', false), __('General', false));

        // Recargar la aplicación completa para establecer nuevos valores
        Util::reload();

        $this->saveConfig();
    }

    /**
     * Guardar la configuración
     *
     * @throws \SP\Core\Exceptions\SPException
     */
    protected function saveConfig()
    {
        try {
            if (Checks::demoIsEnabled()) {
                $this->JsonResponse->setDescription(__('Ey, esto es una DEMO!!', false));
                return;
            }

            Config::saveConfig();

            $this->JsonResponse->setStatus(0);

            $this->LogMessage->addDescription(__('Configuración actualizada', false));
        } catch (SPException $e) {
            $this->LogMessage->addDescription(__('Error al guardar la configuración', false));
            $this->LogMessage->addDetails($e->getMessage(), $e->getHint());
        }

        $this->LogMessage->setAction(__('Modificar Configuración', false));

        Email::sendEmail($this->LogMessage);
    }

    /**
     * Acción para opciones de Wiki
     *
     * @throws \SP\Core\Exceptions\SPException
     */
    protected function wikiAction()
    {
        $Config = Session::getConfig();

        // Wiki
        $wikiEnabled = Request::analyze('wiki_enabled', false, false, true);
        $wikiSearchUrl = Request::analyze('wiki_searchurl');
        $wikiPageUrl = Request::analyze('wiki_pageurl');
        $wikiFilter = Request::analyze('wiki_filter');

        // Valores para la conexión a la Wiki
        if ($wikiEnabled && (!$wikiSearchUrl || !$wikiPageUrl || !$wikiFilter)) {
            $this->JsonResponse->setDescription(__('Faltan parámetros de Wiki', false));
            return;
        } elseif ($wikiEnabled) {
            $Config->setWikiEnabled(true);
            $Config->setWikiSearchurl($wikiSearchUrl);
            $Config->setWikiPageurl($wikiPageUrl);
            $Config->setWikiFilter(explode(',', $wikiFilter));

            $this->LogMessage->addDescription(__('Wiki habiltada', false));
        } elseif ($Config->isWikiEnabled()) {
            $Config->setWikiEnabled(false);

            $this->LogMessage->addDescription(__('Wiki deshabilitada', false));
        }

        // DokuWiki
        $dokuWikiEnabled = Request::analyze('dokuwiki_enabled', false, false, true);
        $dokuWikiUrl = Request::analyze('dokuwiki_url');
        $dokuWikiUrlBase = Request::analyze('dokuwiki_urlbase');
        $dokuWikiUser = Request::analyze('dokuwiki_user');
        $dokuWikiPass = Request::analyzeEncrypted('dokuwiki_pass');
        $dokuWikiNamespace = Request::analyze('dokuwiki_namespace');

        // Valores para la conexión a la API de DokuWiki
        if ($dokuWikiEnabled && (!$dokuWikiUrl || !$dokuWikiUrlBase)) {
            $this->JsonResponse->setDescription(__('Faltan parámetros de DokuWiki', false));
            return;
        } elseif ($dokuWikiEnabled) {
            $Config->setDokuwikiEnabled(true);
            $Config->setDokuwikiUrl($dokuWikiUrl);
            $Config->setDokuwikiUrlBase(trim($dokuWikiUrlBase, '/'));
            $Config->setDokuwikiUser($dokuWikiUser);
            $Config->setDokuwikiPass($dokuWikiPass);
            $Config->setDokuwikiNamespace($dokuWikiNamespace);

            $this->LogMessage->addDescription(__('DokuWiki habiltada', false));
        } elseif ($Config->isDokuwikiEnabled()) {
            $Config->setDokuwikiEnabled(false);

            $this->LogMessage->addDescription(__('DokuWiki deshabilitada', false));
        }

        $this->LogMessage->addDetails(__('Sección', false), __('Wiki', false));

        $this->saveConfig();
    }

    /**
     * Acción para opciones de LDAP
     *
     * @throws \SP\Core\Exceptions\SPException
     */
    protected function ldapAction()
    {
        $Config = Session::getConfig();

        // LDAP
        $ldapEnabled = Request::analyze('ldap_enabled', false, false, true);
        $ldapADSEnabled = Request::analyze('ldap_ads', false, false, true);
        $ldapServer = Request::analyze('ldap_server');
        $ldapBase = Request::analyze('ldap_base');
        $ldapGroup = Request::analyze('ldap_group');
        $ldapDefaultGroup = Request::analyze('ldap_defaultgroup', 0);
        $ldapDefaultProfile = Request::analyze('ldap_defaultprofile', 0);
        $ldapBindUser = Request::analyze('ldap_binduser');
        $ldapBindPass = Request::analyzeEncrypted('ldap_bindpass');

        // Valores para la configuración de LDAP
        if ($ldapEnabled && (!$ldapServer || !$ldapBase || !$ldapBindUser)) {
            $this->JsonResponse->setDescription(__('Faltan parámetros de LDAP'));
            return;
        } elseif ($ldapEnabled) {
            $Config->setLdapEnabled(true);
            $Config->setLdapAds($ldapADSEnabled);
            $Config->setLdapServer($ldapServer);
            $Config->setLdapBase($ldapBase);
            $Config->setLdapGroup($ldapGroup);
            $Config->setLdapDefaultGroup($ldapDefaultGroup);
            $Config->setLdapDefaultProfile($ldapDefaultProfile);
            $Config->setLdapBindUser($ldapBindUser);
            $Config->setLdapBindPass($ldapBindPass);

            $this->LogMessage->addDescription(__('LDAP habiltado', false));
        } elseif ($Config->isLdapEnabled()) {
            $Config->setLdapEnabled(false);

            $this->LogMessage->addDescription(__('LDAP deshabilitado', false));
        }

        $this->LogMessage->addDetails(__('Sección', false), __('LDAP', false));
        $this->JsonResponse->setStatus(0);

        $this->saveConfig();
    }

    /**
     * Accion para opciones de correo
     *
     * @throws \SP\Core\Exceptions\SPException
     */
    protected function mailAction()
    {
        $Log = Log::newLog(__('Modificar Configuración', false));
        $Config = Session::getConfig();

        // Mail
        $mailEnabled = Request::analyze('mail_enabled', false, false, true);
        $mailServer = Request::analyze('mail_server');
        $mailPort = Request::analyze('mail_port', 25);
        $mailUser = Request::analyze('mail_user');
        $mailPass = Request::analyzeEncrypted('mail_pass');
        $mailSecurity = Request::analyze('mail_security');
        $mailFrom = Request::analyze('mail_from');
        $mailRequests = Request::analyze('mail_requestsenabled', false, false, true);
        $mailAuth = Request::analyze('mail_authenabled', false, false, true);

        // Valores para la configuración del Correo
        if ($mailEnabled && (!$mailServer || !$mailFrom)) {
            $this->JsonResponse->setDescription(__('Faltan parámetros de Correo'));
            return;
        } elseif ($mailEnabled) {
            $Config->setMailEnabled(true);
            $Config->setMailRequestsEnabled($mailRequests);
            $Config->setMailServer($mailServer);
            $Config->setMailPort($mailPort);
            $Config->setMailSecurity($mailSecurity);
            $Config->setMailFrom($mailFrom);

            if ($mailAuth) {
                $Config->setMailAuthenabled($mailAuth);
                $Config->setMailUser($mailUser);
                $Config->setMailPass($mailPass);
            }

            $this->LogMessage->addDescription(__('Correo habiltado', false));
        } elseif ($Config->isMailEnabled()) {
            $Config->setMailEnabled(false);
            $Config->setMailRequestsEnabled(false);
            $Config->setMailAuthenabled(false);

            $this->LogMessage->addDescription(__('Correo deshabilitado', false));
        }

        $this->LogMessage->addDetails(__('Sección', false), __('Correo', false));
        $this->JsonResponse->setStatus(0);

        $this->saveConfig();
    }

    /**
     * Acción para cambio de clave maestra
     *
     * @throws \SP\Core\Exceptions\SPException
     * @throws \SP\Core\Exceptions\InvalidClassException
     * @throws \phpmailer\phpmailerException
     */
    protected function masterPassAction()
    {
        $currentMasterPass = Request::analyzeEncrypted('curMasterPwd');
        $newMasterPass = Request::analyzeEncrypted('newMasterPwd');
        $newMasterPassR = Request::analyzeEncrypted('newMasterPwdR');
        $confirmPassChange = Request::analyze('confirmPassChange', 0, false, 1);
        $noAccountPassChange = Request::analyze('chkNoAccountChange', 0, false, 1);

        if (!UserPass::getItem(Session::getUserData())->checkUserUpdateMPass()) {
            $this->JsonResponse->setDescription(__('Clave maestra actualizada', false));
            $this->JsonResponse->addMessage(__('Reinicie la sesión para cambiarla', false));
            return;
        } elseif ($newMasterPass === '' && $currentMasterPass === '') {
            $this->JsonResponse->setDescription(__('Clave maestra no indicada'));
            return;
        } elseif ($confirmPassChange === false) {
            $this->JsonResponse->setDescription(__('Se ha de confirmar el cambio de clave', false));
            return;
        }

        if ($newMasterPass === $currentMasterPass) {
            $this->JsonResponse->setDescription(__('Las claves son idénticas', false));
            return;
        } elseif ($newMasterPass !== $newMasterPassR) {
            $this->JsonResponse->setDescription(__('Las claves maestras no coinciden', false));
            return;
        } elseif (!Crypt::checkHashPass($currentMasterPass, ConfigDB::getValue('masterPwd'), true)) {
            $this->JsonResponse->setDescription(__('La clave maestra actual no coincide', false));
            return;
        }

        if (Checks::demoIsEnabled()) {
            $this->JsonResponse->setDescription(__('Ey, esto es una DEMO!!', false));
            return;
        }

        $hashMPass = Crypt::mkHashPassword($newMasterPass);

        if (!$noAccountPassChange) {
            $Account = new Account();

            if (!$Account->updateAccountsMasterPass($currentMasterPass, $newMasterPass)) {
                $this->JsonResponse->setDescription(__('Errores al actualizar las claves de las cuentas', false));
                return;
            }

            $AccountHistory = new AccountHistory();

            if (!$AccountHistory->updateAccountsMasterPass($currentMasterPass, $newMasterPass, $hashMPass)) {
                $this->JsonResponse->setDescription(__('Errores al actualizar las claves de las cuentas del histórico', false));
                return;
            }

            if (!CustomFieldsUtil::updateCustomFieldsCrypt($currentMasterPass, $newMasterPass)) {
                $this->JsonResponse->setDescription(__('Errores al actualizar datos de campos personalizados', false));
                return;
            }
        }

        ConfigDB::setCacheConfigValue('masterPwd', $hashMPass);
        ConfigDB::setCacheConfigValue('lastupdatempass', time());

        $this->LogMessage->setAction(__('Actualizar Clave Maestra', false));

        if (ConfigDB::writeConfig()) {
            $this->LogMessage->addDescription(__('Clave maestra actualizada', false));
            $this->JsonResponse->setStatus(0);
        } else {
            $this->LogMessage->addDescription(__('Error al guardar el hash de la clave maestra', false));
        }

        Email::sendEmail($this->LogMessage);
    }

    /**
     * Acción para generar clave maestra temporal
     *
     * @throws \SP\Core\Exceptions\SPException
     * @throws \phpmailer\phpmailerException
     */
    protected function tempMasterPassAction()
    {
        $tempMasterMaxTime = Request::analyze('tmpass_maxtime', 3600);
        $tempMasterPass = CryptMasterPass::setTempMasterPass($tempMasterMaxTime);

        $this->LogMessage->setAction(__('Generar Clave Temporal', false));

        if ($tempMasterPass !== false && !empty($tempMasterPass)) {
            $this->LogMessage->addDescription(__('Clave Temporal Generada', false));
            $this->LogMessage->addDetails(__('Clave', false), $tempMasterPass);

            $this->JsonResponse->setStatus(0);
        } else {
            $this->LogMessage->addDescription(__('Error al generar clave temporal', false));
        }

        Email::sendEmail($this->LogMessage);
    }

    /**
     * Acción para importar cuentas
     *
     * @throws \SP\Core\Exceptions\SPException
     */
    protected function importAction()
    {
        if (Checks::demoIsEnabled()) {
            $this->JsonResponse->setDescription(__('Ey, esto es una DEMO!!', false));
            return;
        }

        $ImportParams = new ImportParams();
        $ImportParams->setDefaultUser(Request::analyze('defUser', Session::getUserData()->getUserId()));
        $ImportParams->setDefaultGroup(Request::analyze('defGroup', Session::getUserData()->getUserGroupId()));
        $ImportParams->setImportPwd(Request::analyzeEncrypted('importPwd'));
        $ImportParams->setImportMasterPwd(Request::analyzeEncrypted('importMasterPwd'));
        $ImportParams->setCsvDelimiter(Request::analyze('csvDelimiter'));

        $Import = new Import($ImportParams);
        $Message = $Import->doImport($_FILES['inFile']);

        $this->JsonResponse->setDescription($Message->getDescription());
        $this->JsonResponse->addMessage($Message->getHint());
        $this->JsonResponse->setStatus(0);
    }

    /**
     * Acción para exportar cuentas
     */
    protected function exportAction()
    {
        $exportPassword = Request::analyzeEncrypted('exportPwd');
        $exportPasswordR = Request::analyzeEncrypted('exportPwdR');

        if (!empty($exportPassword) && $exportPassword !== $exportPasswordR) {
            $this->JsonResponse->setDescription(__('Las claves no coinciden', false));
            return;
        }

        if (!XmlExport::doExport($exportPassword)) {
            $this->JsonResponse->setDescription(__('Error al realizar la exportación', false));
            $this->JsonResponse->addMessage(__('Revise el registro de eventos para más detalles', false));
            return;
        }

        $this->JsonResponse->setDescription(__('Proceso de exportación finalizado', false));
        $this->JsonResponse->setStatus(0);
    }

    /**
     * Acción para realizar el backup de sysPass
     */
    protected function backupAction()
    {
        if (Checks::demoIsEnabled()) {
            $this->JsonResponse->setDescription(__('Ey, esto es una DEMO!!', false));
            return;
        }

        if (!Backup::doBackup()) {
            $this->JsonResponse->setDescription(__('Error al realizar el backup', false));
            $this->JsonResponse->addMessage(__('Revise el registro de eventos para más detalles', false));
            return;
        }

        $this->JsonResponse->setDescription(__('Proceso de backup finalizado', false));
        $this->JsonResponse->setStatus(0);
    }
}