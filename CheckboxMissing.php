<?php
/**
 * Checkbox Missing Helper
 *
 * Provides @CHECKBOXMISSING action tag for checkbox fields.
 * Auto-creates and maintains a companion fieldname_missing CALC field whose
 * equation evaluates to 1 when all checkbox options are unchecked, and 0
 * when at least one option is checked.
 *
 * REDCap's native calc engine handles all real-time updates on forms,
 * surveys, repeating instruments, repeating events, and API/imports.
 * No JavaScript required on data entry forms.
 *
 * On the Online Designer, a MutationObserver detects field saves and
 * triggers updates via AJAX. Formula changes need no refresh; the page
 * reloads once when a companion field is created or renamed (to follow a
 * renamed checkbox) so the field table shows it.
 *
 * @author Kerrin Palazzi, Hunter Medical Research Institute
 */

namespace HMRI\CheckboxMissing;

use ExternalModules\AbstractExternalModule;

class CheckboxMissing extends AbstractExternalModule
{
    const ACTION_TAG      = '@CHECKBOXMISSING';
    const MISS_SUFFIX     = '_missing';  // fallback default
    const MISS_LABEL_OLD  = '[auto] Checkbox missing flag';  // label used before the per-field label
    const MISS_ANNOTATION = '@HIDDEN';

    /** @var int Current project id */
    protected $pid;

    /** @var array fieldname => [codes] for all @CHECKBOXMISSING fields on this project */
    protected $taggedFields = [];

    /**
     * Returns the configured companion field suffix for the current project,
     * falling back to the default '_missing' if not set or invalid.
     *
     * Valid suffixes must start with an underscore and contain only lowercase
     * letters, numbers, and underscores — matching REDCap's field name rules.
     */
    protected function getSuffix(): string
    {
        $setting = trim((string)($this->getProjectSetting('companion-suffix') ?? ''));

        // Validate: must start with underscore, only lowercase alphanumeric + underscores
        if ($setting !== '' && preg_match('/^_[a-z0-9_]+$/', $setting)) {
            return $setting;
        }

        return self::MISS_SUFFIX;
    }

    /**
     * Returns the metadata table name to use for reads and writes.
     *
     * In production mode REDCap stages all data dictionary changes in
     * redcap_metadata_temp (the draft table) until they are approved.
     * The Online Designer reads from and writes to this draft table, so
     * our EM must do the same — otherwise we miss action tags the user
     * just added and write companion fields to the wrong table.
     *
     * draft_mode = 0 → no pending draft, use redcap_metadata
     * draft_mode = 1 → draft pending,    use redcap_metadata_temp
     */
    protected function getMetadataTable(int $project_id): string
    {
        $result = db_query(
            "SELECT draft_mode FROM redcap_projects
             WHERE project_id = " . db_escape($project_id)
        );
        $row = db_fetch_assoc($result);
        return (!empty($row['draft_mode'])) ? 'redcap_metadata_temp' : 'redcap_metadata';
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Fires on every project page; does nothing except on two pages:
     *
     * - After a successful Data Dictionary upload: updates companion fields
     *   straight away in PHP, silently (no banner).
     * - With an instrument open in the Online Designer: injects the JS that
     *   updates companion fields via AJAX on page load and after each field
     *   save, and shows banners above the field table.
     */
    public function redcap_every_page_top($project_id)
    {
        if (!$project_id) return;

        $page = defined('PAGE') ? PAGE : '';

        // REDCap redirects here with upload_success once the uploaded
        // dictionary is committed, so the new metadata is already saved
        if (strpos($page, 'Design/data_dictionary_upload.php') !== false) {
            if (isset($_GET['upload_success'])) {
                $this->updateAllFormulas((int)$project_id);
            }
            return;
        }

        // Online Designer, instrument view only (not the instrument list),
        // so banners always appear above the field table
        if (strpos($page, 'Design/online_designer.php') === false) return;
        if (empty($_GET['page'])) return;

        // The CSRF token is required on module POST requests (framework v8+)
        $ajaxUrl = $this->getUrl('ajax/update_formulas.php');
        echo '<script>'
           . 'var checkboxMissingAjaxUrl = ' . json_encode($ajaxUrl) . ';'
           . 'var checkboxMissingProjectId = ' . intval($project_id) . ';'
           . 'var checkboxMissingCsrfToken = ' . json_encode($this->getCSRFToken()) . ';'
           . '</script>';
        $jsUrl = $this->getUrl('js/online_designer.js');
        echo '<script src="' . $jsUrl . '"></script>';
    }

    // -------------------------------------------------------------------------
    // Core update logic (called from AJAX endpoint)
    // -------------------------------------------------------------------------

    /**
     * Checks all @CHECKBOXMISSING tagged fields in a project and creates,
     * renames or updates companion calc fields as needed.
     *
     * Renames are handled before creation: if a tagged checkbox has no
     * companion but an orphaned companion (one whose source checkbox no
     * longer exists) sits on the same instrument, the checkbox was renamed,
     * so the orphan is renamed to match rather than creating a second field.
     *
     * Returns a structured array used to build the JSON AJAX response.
     *
     * @return array{status: string, created: array, renamed: array, updated: string[], relabelled: string[]}
     */
    public function updateAllFormulas(int $project_id): array
    {
        $this->pid          = $project_id;
        $this->taggedFields = $this->getTaggedFields();

        $result = [
            'status'     => 'ok',
            'created'    => [],  // [ ['field' => ..., 'source' => ...], ... ]
            'renamed'    => [],  // [ ['from' => ..., 'to' => ...], ... ]
            'updated'    => [],  // formula changed
            'relabelled' => [],  // old '[auto]' label replaced
        ];

        if (empty($this->taggedFields)) return $result;

        $table   = $this->getMetadataTable($project_id);
        $suffix  = $this->getSuffix();
        $fields  = $this->getAllFields($table);
        $orphans = $this->findOrphanCompanions($fields, $suffix);

        // Tagged checkboxes with no companion under their current name, and
        // which of them were renamed (matched to an orphaned companion).
        // All matches are worked out before anything is changed.
        $needing = array_values(array_filter(
            array_keys($this->taggedFields),
            fn($cb) => !isset($fields[$cb . $suffix])
        ));
        $matches = $this->matchOrphans($needing, $fields, $orphans);

        $changed = false;

        foreach ($this->taggedFields as $cbField => $codes) {
            $missField = $cbField . $suffix;
            $formula   = $this->buildFormula($cbField, $codes);

            if (!isset($fields[$missField])) {
                if (isset($matches[$cbField])) {
                    $orphan = $matches[$cbField];
                    // Only replace the label if the module wrote it; keep hand edits
                    $newLabel = $this->isModuleLabel($orphan['element_label'], $orphan['source'])
                        ? $this->buildLabel($cbField)
                        : null;
                    $this->renameCompanionField($table, $orphan['field_name'], $missField, $formula, $newLabel);
                    $result['renamed'][] = [
                        'from'       => $orphan['field_name'],
                        'to'         => $missField,
                        'references' => $this->updateReferences($table, $orphan['field_name'], $missField),
                    ];
                    $changed = true;
                } elseif ($this->createCompanionField($project_id, $cbField, $codes)) {
                    $result['created'][] = ['field' => $missField, 'source' => $cbField];
                    $changed = true;
                }
                continue;
            }

            $existing = $fields[$missField];

            if (trim($existing['element_enum'] ?? '') !== $formula) {
                $this->updateCompanionColumn($table, $missField, 'element_enum', $formula);
                $result['updated'][] = $missField;
                $changed = true;
            }

            // Brings labels written by earlier builds up to the current
            // wording. Labels edited by hand are left alone.
            if ($this->needsRelabel($existing['element_label'] ?? null, $cbField)) {
                $this->updateCompanionColumn($table, $missField, 'element_label', $this->buildLabel($cbField));
                $result['relabelled'][] = $missField;
                $changed = true;
            }
        }

        if ($changed) $this->resetProjectCache($project_id);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Field creation and updating
    // -------------------------------------------------------------------------

    /**
     * Creates the companion calc field immediately after the source
     * checkbox field in the data dictionary.
     *
     * Returns false if nothing was inserted, so the caller doesn't report a
     * creation (the Online Designer reloads after each reported creation).
     */
    protected function createCompanionField(int $project_id, string $cbField, array $codes): bool
    {
        $table      = $this->getMetadataTable($project_id);
        $missField  = $cbField . $this->getSuffix();
        $formula    = $this->buildFormula($cbField, $codes);

        // Read the source checkbox field's metadata from the correct table
        $cbResult = db_query(
            "SELECT form_name, field_order FROM " . $table . "
             WHERE project_id = " . db_escape($project_id) . "
               AND field_name = '" . db_escape($cbField) . "'"
        );
        $cbMeta = db_fetch_assoc($cbResult);
        if (!$cbMeta) return false;

        $instrument = $cbMeta['form_name'];
        $cbOrder    = (int)$cbMeta['field_order'];
        $newOrder   = $cbOrder + 1;

        // Shift all fields after the checkbox down by one to make room
        db_query(
            "UPDATE " . $table . "
             SET field_order = field_order + 1
             WHERE project_id = " . db_escape($project_id) . "
               AND form_name = '" . db_escape($instrument) . "'
               AND field_order >= " . db_escape($newOrder)
        );

        $inserted = db_query(
            "INSERT INTO " . $table . "
             (project_id, field_name, form_name, field_order, element_label,
              element_type, element_enum,
              element_preceding_header, element_note,
              element_validation_type,
              element_validation_min, element_validation_max,
              field_phi, misc)
             VALUES (
               " . db_escape($project_id) . ",
               '" . db_escape($missField) . "',
               '" . db_escape($instrument) . "',
               " . db_escape($newOrder) . ",
               '" . db_escape($this->buildLabel($cbField)) . "',
               'calc',
               '" . db_escape($formula) . "',
               NULL, NULL, NULL, NULL, NULL, 0,
               '" . db_escape(self::MISS_ANNOTATION) . "'
             )"
        );

        return (bool)$inserted;
    }

    /**
     * Renames an orphaned companion field to follow its renamed checkbox,
     * keeping its position, and points its formula at the new name.
     *
     * Only the data dictionary is changed, matching what REDCap does when the
     * checkbox itself is renamed: values already saved under the old names
     * stay under the old names.
     *
     * @param string|null $label New label, or null to keep the current one
     */
    protected function renameCompanionField(string $table, string $oldName, string $newName, string $formula, ?string $label): void
    {
        $labelSql = ($label === null) ? '' : ", element_label = '" . db_escape($label) . "'";

        db_query(
            "UPDATE " . $table . "
             SET field_name = '" . db_escape($newName) . "',
                 element_enum = '" . db_escape($formula) . "'" . $labelSql . "
             WHERE project_id = " . db_escape($this->pid) . "
               AND field_name = '" . db_escape($oldName) . "'"
        );
    }

    /**
     * After a companion is renamed, points data dictionary references to its
     * old name at the new name, so logic like [ethnicity_missing]=1 keeps
     * working. Covers branching logic, calc equations, labels, field notes,
     * section headers and action tags (e.g. @CALCTEXT, @IF).
     *
     * Only [oldname] followed by ], : or ( is replaced, so longer names that
     * start with the same text are untouched. Alerts, Data Quality rules,
     * reports and survey settings are not in the data dictionary and are
     * not updated.
     *
     * @return string[] Fields whose metadata was changed
     */
    protected function updateReferences(string $table, string $oldName, string $newName): array
    {
        $columns = ['branching_logic', 'element_enum', 'element_label',
                    'element_note', 'element_preceding_header', 'misc'];

        // LIKE narrows the rows to check ('_' is a LIKE wildcard, so it can
        // over-match); the regex decides what is actually replaced
        $like  = "'%[" . db_escape($oldName) . "%'";
        $where = implode(' OR ', array_map(fn($c) => $c . ' LIKE ' . $like, $columns));

        $result = db_query(
            "SELECT field_name, " . implode(', ', $columns) . "
             FROM " . $table . "
             WHERE project_id = " . db_escape($this->pid) . "
               AND field_name <> '" . db_escape($newName) . "'
               AND (" . $where . ")"
        );

        $changed = [];
        while ($row = db_fetch_assoc($result)) {
            $sets = [];
            foreach ($columns as $column) {
                if ($row[$column] === null) continue;
                $updated = $this->replaceReference($row[$column], $oldName, $newName);
                if ($updated !== $row[$column]) {
                    $sets[] = $column . " = '" . db_escape($updated) . "'";
                }
            }
            if (empty($sets)) continue;

            db_query(
                "UPDATE " . $table . "
                 SET " . implode(', ', $sets) . "
                 WHERE project_id = " . db_escape($this->pid) . "
                   AND field_name = '" . db_escape($row['field_name']) . "'"
            );
            $changed[] = $row['field_name'];
        }

        return $changed;
    }

    /**
     * Replaces references to one field name with another in a piece of
     * logic or text: [old] [old:value] [old(1)] [event][old] all change,
     * [old_suffix] and [prefix_old] don't.
     */
    protected function replaceReference(string $text, string $oldName, string $newName): string
    {
        $pattern = '/\[' . preg_quote($oldName, '/') . '(?=[\]:(])/';
        return preg_replace($pattern, '[' . $newName, $text);
    }

    /**
     * Updates one metadata column of an existing companion field.
     * Used for the formula (checkbox options added or removed) and the label.
     */
    protected function updateCompanionColumn(string $table, string $missField, string $column, string $value): void
    {
        // $column is only ever passed as a literal by this class
        if (!in_array($column, ['element_enum', 'element_label'], true)) return;

        db_query(
            "UPDATE " . $table . "
             SET " . $column . " = '" . db_escape($value) . "'
             WHERE project_id = " . db_escape($this->pid) . "
               AND field_name = '" . db_escape($missField) . "'"
        );
    }

    /**
     * Resets REDCap's in-memory Project object so metadata changes
     * are reflected immediately within the same request.
     */
    protected function resetProjectCache(int $project_id): void
    {
        global $Proj;
        if (isset($Proj) && $Proj->project_id == $project_id) {
            $Proj = new \Project($project_id);
        }
    }

    // -------------------------------------------------------------------------
    // Core helpers
    // -------------------------------------------------------------------------

    /**
     * Returns all fields in this project annotated with @CHECKBOXMISSING,
     * mapped to their checkbox choice codes.
     *
     * Queries the metadata table directly (using the draft table in production
     * draft mode) rather than via new \Project(), which always reads the live
     * redcap_metadata table and would miss tags added during a draft session.
     *
     * @return array [ 'fieldname' => ['code1', 'code2', ...], ... ]
     */
    protected function getTaggedFields(): array
    {
        $table  = $this->getMetadataTable($this->pid);
        $tagged = [];

        $result = db_query(
            "SELECT field_name, element_enum, misc
             FROM " . $table . "
             WHERE project_id = " . db_escape($this->pid) . "
               AND element_type = 'checkbox'
               AND misc LIKE '%" . db_escape(self::ACTION_TAG) . "%'"
        );

        while ($row = db_fetch_assoc($result)) {
            $codes = $this->parseChoiceCodes($row['element_enum']);
            if (!empty($codes)) {
                $tagged[$row['field_name']] = $codes;
            }
        }

        return $tagged;
    }

    /**
     * Returns every field in the project's metadata table, keyed by field name.
     *
     * @return array [ 'fieldname' => ['field_name', 'form_name', 'field_order',
     *                                  'element_type', 'element_enum', 'element_label'], ... ]
     */
    protected function getAllFields(string $table): array
    {
        $fields = [];

        $result = db_query(
            "SELECT field_name, form_name, field_order, element_type, element_enum, element_label
             FROM " . $table . "
             WHERE project_id = " . db_escape($this->pid)
        );

        while ($row = db_fetch_assoc($result)) {
            $fields[$row['field_name']] = $row;
        }

        return $fields;
    }

    /**
     * Finds companion fields whose source checkbox no longer exists —
     * i.e. the checkbox was renamed (or deleted) after the companion was made.
     *
     * A field only counts as a companion if it's a calc field named
     * <source><suffix> whose equation is exactly what buildFormula() would
     * generate for <source>, so hand-made calc fields are never touched.
     *
     * @return array [ 'old_companion_name' => field row + ['source' => old checkbox name], ... ]
     */
    protected function findOrphanCompanions(array $fields, string $suffix): array
    {
        $orphans = [];

        foreach ($fields as $name => $row) {
            if ($row['element_type'] !== 'calc') continue;

            $formula = trim($row['element_enum'] ?? '');
            if (!preg_match('/^if\(\[([a-z0-9_]+)\(/', $formula, $m)) continue;
            $source = $m[1];

            // Must be named after its source and its source must be gone
            if ($name !== $source . $suffix || isset($fields[$source])) continue;

            // Must be our generated equation, exactly
            preg_match_all('/\[' . preg_quote($source, '/') . '\(([^)]*)\)\]=\'0\'/', $formula, $codes);
            if (empty($codes[1]) || $this->buildFormula($source, $codes[1]) !== $formula) continue;

            $orphans[$name] = $row + ['source' => $source];
        }

        return $orphans;
    }

    /**
     * Matches tagged checkboxes that have no companion to orphaned companions,
     * i.e. works out which checkboxes were renamed.
     *
     * 1. An orphan directly below a checkbox — where it stays after the
     *    checkbox is renamed in the Online Designer.
     * 2. Then, per instrument: if exactly one unmatched checkbox and exactly
     *    one orphan are left, they're matched (covers a companion that was
     *    dragged elsewhere on the form).
     *
     * Anything else is ambiguous and left unmatched, so a new companion is
     * created rather than renaming the wrong one.
     *
     * @param string[] $needing Tagged checkboxes with no companion
     * @return array [ 'checkbox' => orphan row, ... ]
     */
    protected function matchOrphans(array $needing, array $fields, array $orphans): array
    {
        $matches = [];

        foreach ($needing as $cb) {
            foreach ($orphans as $name => $orphan) {
                if ($orphan['form_name'] === $fields[$cb]['form_name']
                    && (int)$orphan['field_order'] === (int)$fields[$cb]['field_order'] + 1) {
                    $matches[$cb] = $orphan;
                    unset($orphans[$name]);
                    break;
                }
            }
        }

        $byForm = [];
        foreach ($needing as $cb) {
            if (!isset($matches[$cb])) $byForm[$fields[$cb]['form_name']]['cb'][] = $cb;
        }
        foreach ($orphans as $orphan) {
            $byForm[$orphan['form_name']]['orphans'][] = $orphan;
        }
        foreach ($byForm as $form) {
            if (count($form['cb'] ?? []) === 1 && count($form['orphans'] ?? []) === 1) {
                $matches[$form['cb'][0]] = $form['orphans'][0];
            }
        }

        return $matches;
    }

    /**
     * Builds the companion field label.
     *
     * The variable name is written without square brackets, because
     * [fieldname] in a label is piped (it would show the checked options).
     * Spaces around <br> keep the words apart where HTML is stripped.
     */
    protected function buildLabel(string $cbField): string
    {
        return '[auto-generated] Checkbox missing flag for ' . $cbField . ' <br> 1 = missing, 0 = not missing';
    }

    /**
     * True if a label is one the module wrote for the given source checkbox,
     * i.e. not edited by hand: the current label, the old generic label, or
     * the versions written by development builds (name in <b></b>, or no
     * space after "for").
     */
    protected function isModuleLabel(?string $label, string $cbField): bool
    {
        $label = trim((string)$label);
        if ($label === self::MISS_LABEL_OLD) return true;

        $pattern = '/^\[auto-generated\] Checkbox missing flag for ?(<b>)?'
                 . preg_quote($cbField, '/')
                 . '(<\/b>)? ?<br> ?1 = missing, 0 = not missing$/';
        return (bool)preg_match($pattern, $label);
    }

    /**
     * True if a companion's label was written by the module but isn't the
     * current wording, so it should be updated. Hand-edited labels are kept.
     */
    protected function needsRelabel(?string $label, string $cbField): bool
    {
        return $this->isModuleLabel($label, $cbField)
            && trim((string)$label) !== $this->buildLabel($cbField);
    }

    /**
     * Builds the REDCap calc formula that equals 1 when all checkbox
     * options are unchecked, and 0 when at least one is checked.
     *
     * Example for ethnicity with codes 1, 2, 3:
     * if([ethnicity(1)]='0' AND [ethnicity(2)]='0' AND [ethnicity(3)]='0', 1, 0)
     */
    protected function buildFormula(string $cbField, array $codes): string
    {
        $conditions = array_map(
            fn($code) => "[{$cbField}({$code})]='0'",
            $codes
        );
        $allUnchecked = implode(' AND ', $conditions);
        return "if({$allUnchecked}, 1, 0)";
    }

    /**
     * Parses REDCap's pipe-delimited choice string into raw code values.
     *
     * For calc field parenthesis notation [field(code)], the original
     * coded value is used as-is — no sanitisation needed.
     *
     * @return string[]
     */
    protected function parseChoiceCodes(string $enumString): array
    {
        $codes = [];
        foreach (explode('\n', $enumString) as $option) {
            $parts = explode(',', $option, 2);
            if (count($parts) >= 1) {
                $raw = trim($parts[0]);
                if ($raw !== '') {
                    $codes[] = $raw;
                }
            }
        }
        return $codes;
    }
}
