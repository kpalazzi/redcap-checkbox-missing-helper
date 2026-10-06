# Change Log
All notable changes to the REDCap Checkbox Missing Helper will be documented in this file.


## [1.0.0] - 2026-10-07
### Summary
 - First release of the Checkbox Missing Helper.
 - `@CHECKBOXMISSING` action tag for checkbox fields. It creates a hidden companion calculation field (`fieldname_missing`; suffix configurable per project) that equals `1` when no options are checked and `0` when at least one is.
 - The companion's equation is updated when checkbox options are added or removed, and the companion is renamed when its checkbox is renamed; references to the old companion name in the data dictionary are updated too.
 - Runs in the Online Designer, with banners above the instrument's fields when companion fields are created, renamed or updated, and silently after a Data Dictionary upload. Works in production draft mode.
 - Requires REDCap 14.6.4 or later (Module Framework version 16).
