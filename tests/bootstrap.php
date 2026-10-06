<?php
/**
 * PHPUnit bootstrap: minimal stand-ins for the REDCap External Module
 * framework, so the module's pure logic can be tested without REDCap or
 * its database. Methods that run SQL are not covered here.
 */

namespace ExternalModules;

abstract class AbstractExternalModule
{
    /** @var array Project settings returned by getProjectSetting(), set per test */
    public $testSettings = [];

    public function getProjectSetting($key, $pid = null)
    {
        return $this->testSettings[$key] ?? null;
    }
}

require_once __DIR__ . '/../CheckboxMissing.php';
