<?php
namespace DieMedialen\DmDeveloperlog\Utility;

/*
 * This file is part of the TYPO3 CMS project.
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
 
class Developerlog {
    protected $extKey = 'dm_developerlog';	// The extension key
    protected $extConf = array(); // The extension configuration

    /**
     * Constructor
     * The constructor just reads the extension configuration and stores it in a member variable
     */
    public function __construct()
    {
        $this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
    }
    
    /**
     * Developer log
     * Parameter is an array containing at most
     * 'msg'		string		Message (in english).
     * 'extKey'		string		Extension key (from which extension you are calling the log)
     * 'severity'	integer		Severity: 0 is info, 1 is notice, 2 is warning, 3 is fatal error, -1 is "OK" message
     * 'dataVar'	array		Additional data you want to pass to the logger.
     *
     * @param array $logArr: log data array
     * @return void
     */
    public function devLog($logArr)
    {
        $minLogLevel = -1;
        if ($this->extConf['minLogLevel'] !== '') {
            $minLogLevel = (int)$this->extConf['minLogLevel'];
        }
        if ((int)$logArr['severity'] < $minLogLevel) {
            return;
        }
        
        if (in_array($logArr['extKey'], \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode(',',$this->extConf['excludeKeys']))) {
            return;
        }
        
        $pid = 0;
        if (TYPO3_MODE == 'FE') {
            $pid = empty($GLOBALS['TSFE']->id) ? 0 : $GLOBALS['TSFE']->id;
        } else {
            if (\TYPO3\CMS\Core\Utility\GeneralUtility::_GET('id')) {
                $pid = (int)\TYPO3\CMS\Core\Utility\GeneralUtility::_GET('id');
            }
        }
        
        $insertFields = array(
            'pid' => $pid,
            'crdate' => $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['tstamp'],
            'crmsec' => $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['mstamp'],
            'cruser_id' => empty($GLOBALS['BE_USER']->user['uid']) ? 0 : $GLOBALS['BE_USER']->user['uid'],
            'line' => 0
        );
        $insertFields['pid'] = $pid;
        
        // Clean up the message before insertion into the database
        // If possible use RemoveXSS (TYPO3 4.2+), otherwise strip all tags
		$message = '';
		if (method_exists(\TYPO3\CMS\Core\Utility\GeneralUtility::class, 'removeXSS')) {
			$message = \TYPO3\CMS\Core\Utility\GeneralUtility::removeXSS($logArr['msg']);
		} else {
			$message = strip_tags($logArr['msg']);
		}
		$insertFields['message'] = $message;
		
        // There's no reason to have any markup in the extension key
		$insertFields['extkey'] = strip_tags($logArr['extKey']);
        
		// Severity can only be a number
		$insertFields['severity'] = intval($logArr['severity']);

		// Try to get information about the place where this method was called from
		if (function_exists('debug_backtrace')) {
			$caller = $this->getCallerInformation(debug_backtrace(false));
			$insertFields['location'] = $caller['location'];
			$insertFields['line'] = $caller['line'];
		}

        if (!empty($logArr['dataVar'])) {
            if (is_array($logArr['dataVar']) && self::isSerializeable($logArr['dataVar'])) {
                $serializedData = serialize($logArr['dataVar']);
                if (!isset($this->extConf['dumpSize']) || strlen($serializedData) <= $this->extConf['dumpSize']) {
                    $insertFields['data_var'] = $serializedData;
                } else {
                    $insertFields['data_var'] = serialize(array('tx_dm_developerlog_error' => 'toolong'));
                }
            } else {
                $insertFields['data_var'] = serialize(array('tx_dm_developerlog_error' => 'invalid'));
            }
        }
        $this->getDatabaseConnection()->exec_INSERTquery('tx_dmdeveloperlog_domain_model_logentry', $insertFields);
    }
    
    /**
	 * Given a backtrace, this method tries to find the place where a "devLog" function was called
	 * and return info about the place
	 *
	 * @param	array	$backTrace: function call backtrace, as provided by debug_backtrace()
	 *
	 * @return	array	information about the call place
	 */
	protected function getCallerInformation($backTrace)
    {
		foreach ($backTrace as $entry) {
			if ($entry['class'] !== self::class && $entry['function'] === 'devLog') {
                $file = $entry['file'];
                if (strpos($file, 'typo3conf/ext') > 0) {
                    $file = substr($file, strpos($file, 'typo3conf/ext'));
                } elseif (strpos($file, 'sysext') > 0) {
                    $file = substr($file, strpos($file, 'sysext'));
                } else {
                    $file = basename($file);
                }
                return array(
                    'location' => $file,
                    'line' => $entry['line']
                );
			}
		}
		return array(
            'class' => '--- unknown ---',
            'line' => '---  unknown ---'
        );
	}
    
    public static function isSerializeable($something)
    {
        if ($something instanceof \Closure) { // cannot be serialized
            return FALSE;
        }
        if (is_object($something)) {
            $objectVars = get_object_vars($something);
            if (is_array($objectVars)) {
                foreach ($objectVars as $objectVar) {
                    if (!self::isSerializeable($objectVar)) {
                        return FALSE;
                    }
                }
            }
        }
        if (is_array($something)) {
            foreach ($something as $partOfSomething) {
                if (!self::isSerializeable($partOfSomething)) {
                    return FALSE;
                }
            }
        }
        return TRUE;
    }
    
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}