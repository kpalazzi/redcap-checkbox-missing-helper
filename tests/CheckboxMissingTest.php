<?php
/**
 * Unit tests for the Checkbox Missing Helper's pure logic: formula and label
 * building, suffix validation, choice parsing, reference replacement, and
 * matching renamed checkboxes to their orphaned companion fields.
 *
 * The priority is anything that could silently point a missing flag at the
 * wrong question or break logic elsewhere in a project.
 */

namespace HMRI\CheckboxMissing\Tests;

use HMRI\CheckboxMissing\CheckboxMissing;
use PHPUnit\Framework\TestCase;

class CheckboxMissingTest extends TestCase
{
    /** @var CheckboxMissing */
    private $module;

    protected function setUp(): void
    {
        $this->module = new CheckboxMissing();
    }

    /** Calls a protected method on the module. */
    private function call(string $method, ...$args)
    {
        $ref = new \ReflectionMethod(CheckboxMissing::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->module, ...$args);
    }

    /** Builds a metadata row as returned by getAllFields(). */
    private function row(string $name, string $form, int $order, string $type = 'checkbox', string $enum = '', string $label = ''): array
    {
        return [
            'field_name'    => $name,
            'form_name'     => $form,
            'field_order'   => (string)$order,  // the database returns strings
            'element_type'  => $type,
            'element_enum'  => $enum,
            'element_label' => $label,
        ];
    }

    /** Builds a companion calc row with the module's own formula. */
    private function companion(string $name, string $source, string $form, int $order, array $codes = ['1', '2']): array
    {
        return $this->row($name, $form, $order, 'calc', $this->call('buildFormula', $source, $codes), '[auto] Checkbox missing flag');
    }

    /** @param array[] $rows */
    private function keyed(array $rows): array
    {
        return array_column($rows, null, 'field_name');
    }

    // -------------------------------------------------------------------------
    // Formula
    // -------------------------------------------------------------------------

    public function testFormulaChecksEveryOptionInOrder(): void
    {
        $this->assertSame(
            "if([eth(1)]='0' AND [eth(3)]='0' AND [eth(2)]='0', 1, 0)",
            $this->call('buildFormula', 'eth', ['1', '3', '2'])
        );
    }

    public function testFormulaWithSingleOption(): void
    {
        $this->assertSame("if([consent(1)]='0', 1, 0)", $this->call('buildFormula', 'consent', ['1']));
    }

    public function testFormulaKeepsNonNumericAndZeroCodes(): void
    {
        // "None of the above" is often coded 0 or 98; text codes are allowed too
        $this->assertSame(
            "if([cb(a)]='0' AND [cb(0)]='0' AND [cb(98)]='0', 1, 0)",
            $this->call('buildFormula', 'cb', ['a', '0', '98'])
        );
    }

    // -------------------------------------------------------------------------
    // Choice parsing (REDCap stores a literal backslash-n between choices)
    // -------------------------------------------------------------------------

    public function testParsesStoredChoices(): void
    {
        $this->assertSame(['1', '2', '98'], $this->call('parseChoiceCodes', '1, Yes \n 2, No \n 98, Unsure'));
    }

    public function testChoiceLabelsContainingCommasKeepTheirCode(): void
    {
        $this->assertSame(['1', '2'], $this->call('parseChoiceCodes', '1, Yes, often \n 2, No, never'));
    }

    public function testBlankChoiceLinesAreIgnored(): void
    {
        $this->assertSame(['1', '2'], $this->call('parseChoiceCodes', '1, A \n  \n 2, B'));
        $this->assertSame([], $this->call('parseChoiceCodes', ''));
    }

    // -------------------------------------------------------------------------
    // Suffix setting
    // -------------------------------------------------------------------------

    /** @dataProvider suffixProvider */
    public function testSuffixValidation($setting, string $expected): void
    {
        $this->module->testSettings['companion-suffix'] = $setting;
        $this->assertSame($expected, $this->call('getSuffix'));
    }

    public function suffixProvider(): array
    {
        return [
            'never saved'          => [null, '_missing'],
            'blank'                => ['', '_missing'],
            'valid custom'         => ['_miss', '_miss'],
            'surrounding spaces'   => [' _blank ', '_blank'],
            'no leading underscore'=> ['miss', '_missing'],
            'uppercase'            => ['_Miss', '_missing'],
            'invalid characters'   => ['_miss-ing', '_missing'],
            'underscore only'      => ['_', '_missing'],
        ];
    }

    // -------------------------------------------------------------------------
    // Label
    // -------------------------------------------------------------------------

    public function testLabelNamesTheCheckbox(): void
    {
        $this->assertSame(
            '[auto-generated] Checkbox missing flag for ethnicity <br> 1 = missing, 0 = not missing',
            $this->call('buildLabel', 'ethnicity')
        );
    }

    public function testLabelDoesNotPipeTheCheckbox(): void
    {
        // [ethnicity] in a label would be piped and show the ticked options
        $this->assertStringNotContainsString('[ethnicity]', $this->call('buildLabel', 'ethnicity'));
    }

    /** @dataProvider moduleLabelProvider */
    public function testRecognisesLabelsTheModuleWrote(?string $label, bool $expected): void
    {
        $this->assertSame($expected, $this->call('isModuleLabel', $label, 'ethnicity'));
    }

    public function moduleLabelProvider(): array
    {
        $current = '[auto-generated] Checkbox missing flag for ethnicity <br> 1 = missing, 0 = not missing';
        return [
            'old generic label'          => ['[auto] Checkbox missing flag', true],
            'current label'              => [$current, true],
            'current label, extra space' => ["  $current\n", true],
            'dev build, bold name'       => ['[auto-generated] Checkbox missing flag for <b>ethnicity</b> <br> 1 = missing, 0 = not missing', true],
            'dev build, no space'        => ['[auto-generated] Checkbox missing flag forethnicity <br> 1 = missing, 0 = not missing', true],
            'label for another checkbox' => [str_replace('ethnicity', 'sex', $current), false],
            'longer name, same start'    => [str_replace('ethnicity', 'ethnicity_v2', $current), false],
            'edited by hand'             => ['Ethnicity not answered', false],
            'current label, added note'  => [$current . ' (see protocol)', false],
            'empty'                      => ['', false],
            'null'                       => [null, false],
        ];
    }

    /** @dataProvider relabelProvider */
    public function testRelabelsOnlyOutdatedModuleLabels(?string $label, bool $expected): void
    {
        $this->assertSame($expected, $this->call('needsRelabel', $label, 'ethnicity'));
    }

    public function relabelProvider(): array
    {
        return [
            'old generic label'    => ['[auto] Checkbox missing flag', true],
            'dev build, bold name' => ['[auto-generated] Checkbox missing flag for <b>ethnicity</b> <br> 1 = missing, 0 = not missing', true],
            'dev build, no space'  => ['[auto-generated] Checkbox missing flag forethnicity <br> 1 = missing, 0 = not missing', true],
            'already current'      => ['[auto-generated] Checkbox missing flag for ethnicity <br> 1 = missing, 0 = not missing', false],
            'edited by hand'       => ['Ethnicity not answered', false],
            'null'                 => [null, false],
        ];
    }

    // -------------------------------------------------------------------------
    // Reference replacement after a companion rename
    // -------------------------------------------------------------------------

    /** @dataProvider referenceProvider */
    public function testReplacesOnlyExactReferences(string $before, string $after): void
    {
        $this->assertSame($after, $this->call('replaceReference', $before, 'eth_missing', 'eth2_missing'));
    }

    public function referenceProvider(): array
    {
        return [
            'branching logic'       => ['[eth_missing]=1', '[eth2_missing]=1'],
            'event prefix'          => ['[event_1_arm_1][eth_missing]=1', '[event_1_arm_1][eth2_missing]=1'],
            'instance suffix'       => ['[eth_missing][2]=1', '[eth2_missing][2]=1'],
            'piping modifier'       => ['Missing: [eth_missing:value]', 'Missing: [eth2_missing:value]'],
            'action tag'            => ["@IF([eth_missing]=1, @HIDDEN, '')", "@IF([eth2_missing]=1, @HIDDEN, '')"],
            'several references'    => ['[eth_missing]=1 or [eth_missing]=0', '[eth2_missing]=1 or [eth2_missing]=0'],
            'longer name, same start' => ['[eth_missing_x]=1', '[eth_missing_x]=1'],
            'longer name, same end' => ['[x_eth_missing]=1', '[x_eth_missing]=1'],
            'text without brackets' => ['eth_missing is a flag', 'eth_missing is a flag'],
            'underscore not a wildcard' => ['[ethXmissing]=1', '[ethXmissing]=1'],
        ];
    }

    // -------------------------------------------------------------------------
    // Orphaned companions (source checkbox renamed or deleted)
    // -------------------------------------------------------------------------

    public function testCompanionOfRenamedCheckboxIsOrphan(): void
    {
        $fields = $this->keyed([
            $this->row('eth_v2', 'demo', 3),
            $this->companion('eth_missing', 'eth', 'demo', 4),
        ]);

        $orphans = $this->call('findOrphanCompanions', $fields, '_missing');

        $this->assertSame(['eth_missing'], array_keys($orphans));
        $this->assertSame('eth', $orphans['eth_missing']['source']);
    }

    public function testCompanionWhoseCheckboxExistsIsNotOrphan(): void
    {
        $fields = $this->keyed([
            $this->row('eth', 'demo', 3),
            $this->companion('eth_missing', 'eth', 'demo', 4),
        ]);

        $this->assertSame([], $this->call('findOrphanCompanions', $fields, '_missing'));
    }

    public function testHandMadeCalcFieldIsNeverOrphan(): void
    {
        // Named like a companion and referencing a missing field, but the
        // equation isn't the module's, so it must not be renamed
        $fields = $this->keyed([
            $this->row('old_missing', 'demo', 4, 'calc', "if([old(1)]='0', 1, 0) + 1"),
            $this->row('other_missing', 'demo', 5, 'calc', "if([old(1)]='0', 1, 0)"),
        ]);

        $this->assertSame([], $this->call('findOrphanCompanions', $fields, '_missing'));
    }

    public function testCompanionWithOtherSuffixIsIgnored(): void
    {
        $fields = $this->keyed([
            $this->companion('eth_miss', 'eth', 'demo', 4),
        ]);

        $this->assertSame([], $this->call('findOrphanCompanions', $fields, '_missing'));
    }

    public function testNonCalcFieldIsNeverOrphan(): void
    {
        $fields = $this->keyed([
            $this->row('eth_missing', 'demo', 4, 'text', "if([eth(1)]='0', 1, 0)"),
        ]);

        $this->assertSame([], $this->call('findOrphanCompanions', $fields, '_missing'));
    }

    // -------------------------------------------------------------------------
    // Matching renamed checkboxes to orphans
    // -------------------------------------------------------------------------

    private function match(array $rows, array $needing): array
    {
        $fields  = $this->keyed($rows);
        $orphans = $this->call('findOrphanCompanions', $fields, '_missing');
        $matches = $this->call('matchOrphans', $needing, $fields, $orphans);
        return array_map(fn($o) => $o['field_name'], $matches);
    }

    public function testMatchesOrphanDirectlyBelowCheckbox(): void
    {
        $this->assertSame(['eth_v2' => 'eth_missing'], $this->match([
            $this->row('eth_v2', 'demo', 3),
            $this->companion('eth_missing', 'eth', 'demo', 4),
        ], ['eth_v2']));
    }

    public function testMatchesTwoRenamesOnOneFormInEitherOrder(): void
    {
        // psych's companion is directly below it; endo's was moved above it
        $rows = [
            $this->row('psych_v2', 'hx', 13),
            $this->companion('psych_missing', 'psych', 'hx', 14),
            $this->companion('endo_missing', 'endo', 'hx', 16),
            $this->row('endo_v2', 'hx', 17),
        ];
        $expected = ['psych_v2' => 'psych_missing', 'endo_v2' => 'endo_missing'];

        $this->assertEquals($expected, $this->match($rows, ['psych_v2', 'endo_v2']));
        $this->assertEquals($expected, $this->match($rows, ['endo_v2', 'psych_v2']));
    }

    public function testMatchesOnlyOrphanWhenOnlyOneCheckboxNeedsOne(): void
    {
        $this->assertSame(['eth_v2' => 'eth_missing'], $this->match([
            $this->companion('eth_missing', 'eth', 'demo', 2),
            $this->row('eth_v2', 'demo', 9),
        ], ['eth_v2']));
    }

    public function testDoesNotGuessWhenAmbiguous(): void
    {
        // One moved orphan, two checkboxes needing a companion: either could
        // be the renamed one, so neither is matched (new companions are made)
        $this->assertSame([], $this->match([
            $this->companion('endo_missing', 'endo', 'hx', 16),
            $this->row('endo_v2', 'hx', 18),
            $this->row('new_cb', 'hx', 30),
        ], ['endo_v2', 'new_cb']));
    }

    public function testDoesNotMatchAcrossInstruments(): void
    {
        $this->assertSame([], $this->match([
            $this->row('eth_v2', 'demo', 3),
            $this->companion('eth_missing', 'eth', 'baseline', 4),
        ], ['eth_v2']));
    }

    public function testDeletedCheckboxOrphanIsLeftAlone(): void
    {
        // Nothing needs a companion, so the orphan isn't matched to anything
        $this->assertSame([], $this->match([
            $this->companion('eth_missing', 'eth', 'demo', 4),
        ], []));
    }
}
