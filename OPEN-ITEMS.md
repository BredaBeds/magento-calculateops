# BredaBeds_CalculateOps: open items

Re-checked against the code on 2026-11-10 (codebase-simplification project, XP-11). This list replaces the
mid-2026 audit brief `REFACTOR.md` (last version: commit `4a9cda5` in this repository). The dead forked core classes and their commented-out preferences were deleted on 2026-11-10; the Mirasvit preference went in 2026-07.
Only the points below were confirmed as still open; the brief's other points were not re-audited one by one.

- `Plugin/ProductPriceViewModelPlugin.php` changes the return shape of Hyvä's
  `ProductPrice::getCustomOptionPrice()` from a float to `['final' => …, 'regular' => …]`. Every caller in the
  theme and in option-templates relies on that. Re-check it on each Hyvä update.
- `Plugin/ProductPriceViewModelPlugin.php:35, 46` keep commented-out returns.
- `Plugin/PriceCalculator.php` is a service, not a plugin; it lives in `Plugin/` and other packages name the
  class, so moving it is a cross-package change.
