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
namespace OxidEsales\TestingLibrary\Services\ModuleInstaller;


use Exception;
use OxidEsales\TestingLibrary\Services\BootstrapNeededService;
use OxidEsales\TestingLibrary\Services\Library\Request;

/**
 * Class for module installation.
 */
class ModuleInstaller extends BootstrapNeededService
{
    /**
     * Starts installation of the OXID eShop.
     *
     * @param Request $request
     *
     */
    public function init($request)
    {
        $modulesToActivate = $request->getParameter("modulestoactivate");
        $moduleDirectory = \OxidEsales\Eshop\Core\Registry::getConfig()->getModulesDir();

        $this->prepareModulesForActivation($moduleDirectory);
        foreach ($modulesToActivate as $modulePath) {
            $this->installModule($modulePath);
        }
    }

    /**
     * Activates module.
     *
     * @param string $modulePath The path to the module.
     *
     * @throws Exception
     */
    public function installModule($modulePath)
    {
        $module = $this->loadModule($modulePath);

        $moduleCache = oxNew(\OxidEsales\Eshop\Core\Module\ModuleCache::class, $module);
        $moduleInstaller = oxNew(\OxidEsales\Eshop\Core\Module\ModuleInstaller::class, $moduleCache);
        if (!$moduleInstaller->activate($module)) {
            throw new Exception("Error on module installation: " . $module->getId());
        }
    }

    /**
     * Prepares modules for activation. Registers all modules that exist in the OXID eShop.
     *
     * @param string $moduleDirectory The base directory of modules.
     */
    private function prepareModulesForActivation($moduleDirectory)
    {
        $moduleList = oxNew(\OxidEsales\Eshop\Core\Module\ModuleList::class);
        $moduleList->getModulesFromDir($moduleDirectory);
    }

    /**
     * Loads module object from given directory.
     *
     * @param string $modulePath The path to the module.
     *
     * @return \OxidEsales\Eshop\Core\Module\Module
     * @throws Exception
     */
    private function loadModule($modulePath)
    {
        $module = oxNew(\OxidEsales\Eshop\Core\Module\Module::class);
        if (!$module->loadByDir($modulePath)) {
            throw new Exception("Module not found");
        }
        return $module;
    }
}
