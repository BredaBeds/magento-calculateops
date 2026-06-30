# REFACTOR.md — BredaBeds_CalculateOps

## How to use this document

This is a **cleanup brief** for the `BredaBeds_CalculateOps` module. It tells you (a future developer
or AI assistant with none of the original context) **why** this module needs work, **what** to do, and
**how** to do it safely without changing behavior. It is **not** a line-by-line spec.

It is a companion to the `REWRITE.md` briefs in the four rewrite-grade modules. **This module is a
special case: despite its size, the job is overwhelmingly to DELETE dead code, not to refactor or
rewrite.** The small part that actually runs is already written the correct way.

## Background: why this cleanup exists

In mid-2026 we audited every `app/code/BredaBeds` module for "overhaul need." **CalculateOps scored
6/10 — but that score is misleading.** The metrics were dominated by ~3,500 lines of **dead** code.

Context: this module's intent — applying catalog price rules to custom-option prices — was originally
implemented the Magento-1 way, by **forking whole core classes and overriding them via `<preference>`**.
The original developer later did the right thing and reimplemented it as small plugins (a git commit
"converted preferences to simple plugins" exists), and even left notes in `di.xml` like *"disabling it
makes no diff."* **They just never deleted the old forks.** So today the module looks far scarier than
it is.

## What the module does today (behavior to preserve)

It applies catalog price rules to custom-option prices on the storefront, and fixes a Mirasvit
cart-price bug. The product prices customers see (with catalog rules applied to options) must remain
identical after cleanup.

## The key fact: ~91% of this module is dead code

- **DEAD (delete):** the forked core classes under `Catalog/**` and `CatalogRule/**`
  (`Product/Option.php`, `CatalogRule/Model/Rule.php`, `Option/Value.php`, `Option/Type/DefaultType.php`,
  `Option/Type/Select.php`, `Block/.../AbstractOptions.php`) plus the duplicate
  `Catalog/Pricing/Price/CalculateCustomOptionCatalogRule.php`. **Every `<preference>` that would wire
  these in is commented out in `etc/di.xml`** — they are unreachable. (~3,200 LOC.) All of the
  module's `ObjectManager` and `Registry` "smells" live in these dead files — they are inherited from
  the copied core, not our own code.
- **LIVE (keep):** the small plugins (`Plugin/ProductOptionPlugin`, `ProductOptionValuePlugin`,
  `SelectTypePlugin`, `ProductPriceViewModelPlugin`) and `Plugin/PriceCalculator.php` (~360 LOC total),
  plus the live Mirasvit override. These already implement the intended behavior the correct way.

## What to do

1. **Delete the dead forked-core files and the stale commented `<preference>` blocks in `di.xml`.**
   This is the bulk of the work and is near-zero risk. *Before deleting, grep the whole codebase to
   confirm nothing references these classes* (they should be referenced only by each other).
2. **Keep the live plugin layer** — do not rewrite it.
3. **Fix the two real live issues** (the only genuine refactor work here):
   - **The Mirasvit override is a full `<preference>` class replacement** of
     `Mirasvit\Tm\Plugin\PushOnCartAddPlugin` (`di.xml`). A whole-class replacement silently diverges
     from the vendor on every Mirasvit upgrade — **convert it to a targeted `before`/`after`/`around`
     plugin** on the specific method instead.
   - **`ProductPriceViewModelPlugin` changes a Hyva view-model's return shape** (scalar → array),
     which is brittle on Hyva upgrades — tighten/own that coupling so a Hyva update can't silently
     break it.
4. **Remove the duplicated price logic and unused dependencies** in the live files (the live
   `PriceCalculator` and the dead helper contained the same algorithm; once the dead one is gone,
   drop the now-unused `$priceCurrency` and dead commented returns).
5. Consider **renaming the module** in the long run — "CalculateOps" is opaque — but only if the cost
   of re-registering is justified; it is not required for this cleanup.

## How we'll do it safely

- **Delete first, verify prices unchanged.** Because the forks are inert, removing them should have
  zero behavioral effect — confirm storefront custom-option prices (with catalog rules) are identical
  before and after.
- **Then make the two live fixes** as small, separately verifiable steps, checking the Mirasvit
  cart-price behavior and the Hyva price display.
- Apply the baseline to any live file you touch: `declare(strict_types=1)`, full types, short array
  syntax, PSR-12 (see the repo `CLAUDE.md`).
- Keep each change independently revertible; lean on the project's rebuild/backup tooling.

## Definition of done

- [ ] All dead forked-core files and their commented `<preference>` blocks are deleted; nothing
      references them.
- [ ] The live plugin layer is preserved and storefront prices are unchanged.
- [ ] The Mirasvit override is a targeted method plugin, not a whole-class `<preference>`.
- [ ] The Hyva return-shape coupling is tightened so a Hyva upgrade can't silently break it.
- [ ] Duplicated price logic and unused dependencies removed from the live files.
- [ ] Any touched live file has `declare(strict_types=1)`, full types, short array syntax, PSR-12.
