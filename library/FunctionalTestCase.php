<?php
/**
 * This file is part of OXID eSales Testing Library.
 *
 * OXID eSales Testing Library is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OXID eSales Testing Library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OXID eSales Testing Library. If not, see <http://www.gnu.org/licenses/>.
 *
 * @link http://www.oxid-esales.com
 * @copyright (C) OXID eSales AG 2003-2014
 */

namespace OxidEsales\TestingLibrary;

use oxDb;
use Exception;
use DateTime;
use OxidEsales\Eshop\Core\Registry;
use ReflectionClass;
use PHPUnit_Framework_ExpectationFailedException as ExpectationFailedException;
use PHPUnit_Framework_AssertionFailedError as AssertionFailedError;
use PHPUnit_Framework_SkippedTest as SkippedTest;
use PHPUnit_Framework_IncompleteTest as IncompleteTest;
use PHPUnit_Util_Filter;

require_once __DIR__ .'/../bootstrap.php';

class FunctionalTestCase
{
    /** @var bool Tracks the start of tests run. */
    protected static $testsSuiteStarted = false;

    /** @var TestConfig */
    private static $testConfig;

    public function __construct()
    {
        define("SHOP_EDITION", ($this->getTestConfig()->getShopEdition() == 'EE') ? 'EE' : 'PE_CE');
    }

    /**
     * Returns test configuration.
     *
     * @return TestConfig
     */
    public static function getTestConfig()
    {
        if (is_null(self::$testConfig)) {
            self::$testConfig = new TestConfig();
        }
        return self::$testConfig;
    }

    /**
     * Sets up shop before running test case.
     * Does not use setUpBeforeClass to keep this method non-static.
     *
     * @param string $testSuitePath
     */
    public function setUp($testSuitePath)
    {
        if (!self::$testsSuiteStarted) {
            self::$testsSuiteStarted = true;
            $this->dumpDb('reset_suite_db_dump');
        } else {
            $this->restoreDb('reset_suite_db_dump');
        }

        $this->addTestData($testSuitePath);
        Registry::getConfig()->reinitialize();
        Registry::set('oxLang', oxNew('oxLang'));
        $oServiceCaller = new ServiceCaller($this->getTestConfig());
        $oServiceCaller->callService('ViewsGenerator');

        $this->dumpDb('reset_test_db_dump');

        $this->clearTemp();
    }

    /**
     * Adds tests sql data to database.
     *
     * @param string $sTestSuitePath
     */
    public function addTestData($sTestSuitePath)
    {
        $config = $this->getTestConfig();
        $testDataPath = realpath($sTestSuitePath . '/testData/');
        if ($testDataPath) {
            $target = $config->getRemoteDirectory() ? $config->getRemoteDirectory() : $config->getShopPath();
            $oFileCopier = new FileCopier();
            $oFileCopier->copyFiles($testDataPath, $target);
        }

        $sTestSuitePath = realpath($sTestSuitePath . '/testSql/');

        $sFileName = $sTestSuitePath . '/demodata.sql';
        if (file_exists($sFileName)) {
            $this->importSql($sFileName);
        }

        $sFileName = $sTestSuitePath . '/demodata_' . SHOP_EDITION . '.sql';
        if (file_exists($sFileName)) {
            $this->importSql($sFileName);
        }

        $sFileName = $sTestSuitePath . '/demodata_EE_mall.sql';
        if ($config->getShopEdition() == 'EE' && $config->isSubShop() && file_exists($sFileName)) {
            $this->importSql($sFileName);
        }
    }

    /**
     * Restores database after every test.
     *
     * @return null
     */
    public function tearDown()
    {
        $this->restoreDB('reset_test_db_dump');
    }

    /**
     * Clears shop cache.
     *
     * @return null
     */
    public function clearTemp()
    {
        $oServiceCaller = new ServiceCaller($this->getTestConfig());
        try {
            $oServiceCaller->setParameter('clearVarnish', $this->getTestConfig()->shouldEnableVarnish());
            $oServiceCaller->callService('ClearCache', 1);
        } catch (Exception $e) {
            $this->fail('Failed to clear cache with message: ' . $e->getMessage());
        }
    }

//----------------------------- Tests BoilerPlate related functions ------------------------------------

    /**
     * Creates a dump of the current database, stored in the file '/tmp/tmp_db_dump'
     * the dump includes the data and sql insert statements.
     *
     * @param string $sTmpPrefix temp file name.
     * @throws Exception on error while dumping.
     * @return null
     */
    public function dumpDB($sTmpPrefix = null)
    {
        $oServiceCaller = new ServiceCaller($this->getTestConfig());
        $oServiceCaller->setParameter('dumpDB', true);
        $oServiceCaller->setParameter('dump-prefix', $sTmpPrefix);
        $oServiceCaller->callService('ShopPreparation', 1);
    }

    /**
     * Checks which tables of the db changed and then restores these tables.
     *
     * Uses dump file '/tmp/tmp_db_dump' for comparison and restoring.
     *
     * @param string $sTmpPrefix temp file name
     * @throws Exception on error while restoring db
     * @return null
     */
    public function restoreDB($sTmpPrefix = null)
    {
        $oServiceCaller = new ServiceCaller($this->getTestConfig());
        $oServiceCaller->setParameter('restoreDB', true);
        $oServiceCaller->setParameter('dump-prefix', $sTmpPrefix);
        $oServiceCaller->callService('ShopPreparation', 1);
    }

    /**
     * Adds some test data to database.
     *
     * @param string $sFilePath
     *
     * @return null
     */
    public function importSql($sFilePath)
    {
        if (filesize($sFilePath)) {
            $oServiceCaller = new ServiceCaller($this->getTestConfig());
            $oServiceCaller->setParameter('importSql', '@' . $sFilePath);
            $oServiceCaller->callService('ShopPreparation', 1);
        }
    }

    /**
     * executes given sql. for EE version cash is also cleared.
     * @param string $sql sql line.
     */
    public function executeSql($sql)
    {
        oxDb::getDb()->execute($sql);
        if ($this->getTestConfig()->getShopEdition() == 'EE') {
            oxDb::getDb()->execute("delete from oxcache");
        }
    }

    /**
     * Call shop selenium connector to execute code in shop.
     * @example call to update information to database.
     *
     * @param string $sClass          class name.
     * @param string $sFnc            function name.
     * @param string $sId             id of object.
     * @param array  $aClassParams    params to set to object.
     * @param array  $aFunctionParams params to set to object.
     * @param string $sShopId         object shop id.
     * @param string $sLang           object shop id.
     *
     * @return mixed
     */
    public function callShopSC(
        $sClass,
        $sFnc,
        $sId = null,
        $aClassParams = array(),
        $aFunctionParams = array(),
        $sShopId = null,
        $sLang = 'en'
    ) {
        $oServiceCaller = new ServiceCaller($this->getTestConfig());
        $oServiceCaller->setParameter('cl', $sClass);
        $oServiceCaller->setParameter('fnc', $sFnc);
        $oServiceCaller->setParameter('oxid', $sId);
        $oServiceCaller->setParameter('lang', $sLang);

        $oServiceCaller->setParameter('classparams', $aClassParams);
        $oServiceCaller->setParameter('functionparams', $aFunctionParams);

        try {
            $mResponse = $oServiceCaller->callService('ShopObjectConstructor', $sShopId);
        } catch (Exception $oException) {
            $this->fail("Exception caught calling ShopObjectConstructor with message: '{$oException->getMessage()}'");
        }

        return $mResponse;
    }
}
