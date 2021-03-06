<?php
namespace TGM\TgmCopyright\Domain\Repository;


/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 Paul Beck <pb@teamgeist-medien.de>, Teamgeist Medien GbR
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * The repository for Copyrights
 */
class CopyrightReferenceRepository extends \TYPO3\CMS\Extbase\Persistence\Repository
{

    /**
     * @param array $settings
     * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     */
    public function findByRootline($settings) {

        $pidClause = $this->getStatementDefaults($settings['rootlines']);
        $additionalClause = '';

        if((int)$settings['displayDuplicateImages']===0) {
            $additionalClause .= ' GROUP BY file.uid';
        }

        // First main statement, exclude by all possible exclusion reasons
        $preQuery = $this->createQuery();

        $now = time();

        $statement = '
          SELECT ref.* FROM sys_file_reference AS ref
          LEFT JOIN sys_file AS file ON (file.uid=ref.uid_local)
          LEFT JOIN sys_file_metadata AS meta ON (file.uid=meta.file)
          LEFT JOIN pages AS p ON (ref.pid=p.uid)
          WHERE (ref.copyright IS NOT NULL OR meta.copyright!="")
          AND p.deleted=0 AND p.hidden=0 AND (p.starttime=0 OR p.starttime<='.$now.') AND (p.endtime=0 OR p.endtime>='.$now.')
          AND file.missing=0 AND file.uid IS NOT NULL
          AND ref.deleted=0 AND ref.hidden=0 AND ref.t3ver_wsid=0 '. $pidClause . $additionalClause;

        $preQuery->statement($statement);

        $preResults = $preQuery->execute(TRUE);

        // Now check if the foreign record has a endtime field which is expired
        $finalRecords = $this->filterPreResultsReturnUids($preResults);

        // Final select
        if(false === empty($finalRecords)) {
            $finalQuery = $this->createQuery();
            return $finalQuery->statement('SELECT * FROM sys_file_reference WHERE uid IN('.implode(',',$finalRecords).')')->execute();
        } else {
            return null;
        }

    }

    /**
     * @param string $rootlines
     * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     */
    public function findForSitemap($rootlines) {

        $pidClause = $this->getStatementDefaults($rootlines);

        // First main statement, exclude by all possible exclusion reasons
        $preQuery = $this->createQuery();

        $now = time();

        $statement = '
          SELECT ref.* FROM sys_file_reference AS ref
          LEFT JOIN sys_file AS file ON (file.uid=ref.uid_local)
          LEFT JOIN pages AS p ON (ref.pid=p.uid)
          WHERE p.deleted=0 AND p.hidden=0 AND (p.starttime=0 OR p.starttime<='.$now.') AND (p.endtime=0 OR p.endtime>='.$now.')
          AND file.missing=0 AND file.uid IS NOT NULL AND (file.type=2 OR file.type=5)
          AND ref.deleted=0 AND ref.hidden=0 AND ref.t3ver_wsid=0 '. $pidClause;

        $preQuery->statement($statement);

        $preResults = $preQuery->execute(TRUE);

        // Now check if the foreign record has a endtime field which is expired
        $finalRecords = $this->filterPreResultsReturnUids($preResults);

        // Final select
        if(false === empty($finalRecords)) {
            $finalQuery = $this->createQuery();
            return $finalQuery->statement('SELECT * FROM sys_file_reference WHERE uid IN('.implode(',',$finalRecords).')')->execute();
        } else {
            return null;
        }

    }

    /**
     * This function will remove remove results which related table records are not hidden by endtime
     * @param array $preResults raw sql results to filter
     * @return array
     */
    public function filterPreResultsReturnUids($preResults) {
        $tableCache = array();
        $finalRecords = array();
        $now = time();
        // TODO: Remove $GLOBALS['TYPO3_DB'] for TYPO3 9 CMS
        foreach($preResults as $preResult) {
            if(isset($preResult['tablenames']) && isset($preResult['uid_foreign'])) {
                if(!array_key_exists($preResult['tablenames'],$tableCache)) {
                    $tableCache[$preResult['tablenames']] = $GLOBALS['TYPO3_DB']->admin_get_fields($preResult['tablenames']);
                }
                // TODO: We could include hidden and deleted here, too. But we've to check if it exists before
                if(isset($tableCache[$preResult['tablenames']]['endtime']) && isset($tableCache[$preResult['tablenames']]['starttime'])) {
                    $foreignRecord = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('uid,endtime',$preResult['tablenames'],'uid='.$preResult['uid_foreign'].' AND hidden=0 AND (starttime=0 OR starttime<='.$now.') AND (endtime=0 OR endtime>='.$now.')');
                    if($foreignRecord===FALSE || $foreignRecord===NULL) {
                        // Exlude if nothing found
                        continue;
                    }
                }
                // Add the record to the final select if the foreign record is not expired or does not have a field endtime
                $finalRecords[] = $preResult['uid'];
            }
        }
        return $finalRecords;
    }

    /**
     * @param string $rootlines
     * @return string
     */
    public function getStatementDefaults($rootlines) {
        $rootlines = (string) $rootlines;
        $sysLanguage = (int) $GLOBALS['TSFE']->sys_language_uid;
        $defaultStatement = ' AND ref.sys_language_uid=' . $sysLanguage;
        if($rootlines!=='') {
            $defaultStatement .= ' AND ref.pid IN('.$this->extendPidListByChildren($rootlines).')';
        } else {
            $defaultStatement .= '';
        }
        return $defaultStatement;
    }

    /**
     * Find all ids from given ids and level by Georg Ringer
     * @param string $pidList comma separated list of ids
     * @param int $recursive recursive levels
     * @return string comma separated list of ids
     */
    private function extendPidListByChildren($pidList = '')
    {
        $recursive = 1000;
        $queryGenerator = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\QueryGenerator::class);
        $recursiveStoragePids = $pidList;
        $storagePids = GeneralUtility::intExplode(',', $pidList);
        foreach ($storagePids as $startPid) {
            $pids = $queryGenerator->getTreeList($startPid, $recursive, 0, 1);
            if (strlen($pids) > 0) {
                $recursiveStoragePids .= ',' . $pids;
            }
        }
        return $recursiveStoragePids;
    }
}
