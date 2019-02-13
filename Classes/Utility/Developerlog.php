<?php
namespace DieMedialen\DmDeveloperlog\Utility;

/*
 * This file is part of the dm_developerlog project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\BackendConfigurationManager;

class Developerlog implements \TYPO3\CMS\Core\SingletonInterface
{
    /** @var string $extkey */
    protected $extKey = 'dm_developerlog';

    protected $logTable = 'tx_dmdeveloperlog_domain_model_logentry';

    /** @var array $extConf */
    protected $extConf = [];

    /** @var string $request_id */
    protected $request_id = '';

    /** @var int $request_type */
    protected $request_type = 0;

    /** @var array $excludeKeys */
    protected $excludeKeys = [];

    /** @var int $currentPageId */
    protected $currentPageId = null;

    protected $systemSearch = '/sysext/';

    protected $systemSearchLength = 8;

    protected $extSeach = '/typo3conf/ext/';

    protected $extSearchLength = 15;

    /**
     * Constructor
     * The constructor just reads the extension configuration and stores it in a member variable
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        if (class_exists(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class)) { // v9
            $this->extConf = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class)->get('dm_developerlog');
        } else {
            $objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
            $this->extConf = $objectManager->get(\TYPO3\CMS\Extensionmanager\Utility\ConfigurationUtility::class)->getCurrentConfiguration('dm_developerlog');
        }
        $this->request_id = $this->getRequestIdFromBootstrapOrLogManager();
        $this->request_type = TYPO3_REQUESTTYPE;
        $this->excludeKeys = GeneralUtility::trimExplode(',', $this->extConf['excludeKeys'], true);
    }

    /**
     * Developer log
     * Parameter is an array containing at most
     * 'msg'        string        Message (in english).
     * 'extKey'        string        Extension key (from which extension you are calling the log)
     * 'severity'    integer        Severity: 0 is info, 1 is notice, 2 is warning, 3 is fatal error, -1 is "OK" message
     * 'dataVar'    mixed        Additional data you want to pass to the logger. This should be an array,
     * but anything but a resource should work
     *
     * @param array $logArray : log data array
     */
    public function devLog($logArray)
    {
        $minLogLevel = -1;
        if ($this->extConf['minLogLevel'] !== '') {
            $minLogLevel = (int)$this->extConf['minLogLevel'];
        }
        if ((int)$logArray['severity'] < $minLogLevel) {
            return;
        }
        if (in_array($logArray['extKey'], $this->excludeKeys)) {
            return;
        }

        $insertFields = $this->getBasicDeveloperLogInformation($logArray);
        if (!empty($this->extConf['includeCallerInformation'])) {
            $callerData = $this->getCallerInformation(debug_backtrace(false));
            $insertFields['location'] = $callerData['location'];
            $insertFields['line'] = $callerData['line'];
            $insertFields['system'] = $callerData['system'];
        }
        if ($this->extConf['dataCap'] !== 0 && isset($logArray['dataVar']) && !is_resource($logArray['dataVar'])) {
            $insertFields['data_var'] = substr(
                $this->getExtraData($logArray['dataVar']),
                0,
                (int)$this->extConf['dataCap']
            );
        }
        $this->createLogEntry($insertFields);
    }

    /**
     * Gather some basic log data
     *
     * @param array $logArray
     *
     * @return array
     */
    protected function getBasicDeveloperLogInformation($logArray)
    {
        $insertFields = [
            'pid' => $this->getCurrentPageId(),
            'crdate' => microtime(true),
            'tstamp' => time(),
            'request_id' => $this->request_id,
            'request_type' => $this->request_type,
            'line' => 0,
        ];

        if (isset($GLOBALS['BE_USER']) && isset($GLOBALS['BE_USER']->user['uid'])) {
            $insertFields['be_user'] = (int)$GLOBALS['BE_USER']->user['uid'];
            $insertFields['workspace_uid'] = (int)$GLOBALS['BE_USER']->workspace;
        }

        if (isset($GLOBALS['TSFE']) && isset($GLOBALS['TSFE']->fe_user->user['uid'])) {
            $insertFields['fe_user'] = (int)$GLOBALS['TSFE']->fe_user->user['uid'];
        }

        $insertFields['message'] = strip_tags($logArray['msg']);

        // There's no reason to have any markup in the extension key
        $insertFields['extkey'] = strip_tags($logArray['extKey']);

        // Severity can only be a number
        $insertFields['severity'] = intval($logArray['severity']);
        return $insertFields;
    }

    /**
     * Get the current page ID (if cheaply available)
     *
     * @return int
     */
    protected function getCurrentPageId()
    {
        if ($this->currentPageId !== null) {
            return $this->currentPageId;
        }
        if (TYPO3_REQUESTTYPE & TYPO3_REQUESTTYPE_FE) {
            $this->currentPageId = $GLOBALS['TSFE']->id ?: 0;
        } else {
            $singletonInstances = GeneralUtility::getSingletonInstances();
            if (isset($singletonInstances[BackendConfigurationManager::class])) { // lucky us, that guy is clever
                $backendConfigurationManager = GeneralUtility::makeInstance(
                    BackendConfigurationManager::class,
                    GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\QueryGenerator::class)
                );
                // getDefaultBackendStoragePid() because someone made getCurrentPageId() protected
                $this->currentPageId = $backendConfigurationManager->getDefaultBackendStoragePid();
            } else {  // simplified backend check
                $this->currentPageId = GeneralUtility::_GP('id') !== null ? (int)GeneralUtility::_GP('id') : 0;
            }
        }
        return $this->currentPageId;
    }

    /**
     * Given a backtrace, this method tries to find the place where a "devLog" function was called
     * and return info about the place
     *
     * @param array $backtrace function call backtrace, as provided by debug_backtrace()
     *
     * @return array information about the call place
     */
    protected function getCallerInformation(array $backtrace)
    {
        $system = 0;
        foreach ($backtrace as $entry) {
            if ($entry['class'] !== self::class && $entry['function'] === 'devLog') {
                $file = $entry['file'];
                if (strpos($file, $this->extSeach) > 0) {
                    $file = substr($file, strpos($file, $this->extSeach) + $this->extSearchLength);
                } elseif (strpos($file, $this->systemSearch) > 0) {
                    $file = substr($file, strpos($file, $this->systemSearch) + $this->systemSearchLength);
                    $system = true;
                } else {
                    $file = basename($file);
                }
                return [
                    'location' => $file,
                    'line' => $entry['line'],
                    'system' => $system,
                ];
            }
        }
        return [
            'location' => '--- unknown ---',
            'line' => 0,
            'system' => $system,
        ];
    }

    /**
     * JSON-encode the extra data provided
     *
     * @param mixed $extraData
     * @return string
     */
    protected function getExtraData($extraData)
    {
        $serializedData = json_encode($extraData, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($serializedData !== false) {
            if (isset($this->extConf['dataCap'])) {
                return substr($serializedData, 0, min(strlen($serializedData), (int)$this->extConf['dataCap']));
            }
            return $serializedData;
        }
        return '';
    }

    /**
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    protected function createLogEntry($insertFields)
    {
        if (class_exists(ConnectionPool::class)) {
            GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->logTable)
                ->insert(
                    $this->logTable,
                    $insertFields
                );
        } else {
            $db = $this->getDatabaseConnection();
            if ($db !== null) { // this can happen when devLog is called to early in the bootstrap process
                @$db->exec_INSERTquery($this->logTable, $insertFields);
            }
        }
    }

    protected function getRequestIdFromBootstrapOrLogManager()
    {
        $bootstrap = \TYPO3\CMS\Core\Core\Bootstrap::getInstance();
        if (method_exists($bootstrap, 'getRequestId')) {
            return \TYPO3\CMS\Core\Core\Bootstrap::getInstance()->getRequestId();
        }
        $logManager = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class);
        $reflectedLogManager = new \ReflectionClass($logManager);
        if ($reflectedLogManager->hasProperty('requestId')) {
            $property = $reflectedLogManager->getProperty('requestId');
            $property->setAccessible(true);
            return $property->getValue($logManager);
        }

        return 'fake-' . substr(md5(uniqid('', true)), 0, 13);
    }
}
