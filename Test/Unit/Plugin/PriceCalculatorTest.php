<?php
declare(strict_types=1);

namespace BredaBeds\CalculateOps\Test\Unit\Plugin;

use BredaBeds\CalculateOps\Plugin\PriceCalculator;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\PriceModifierInterface;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\CatalogRule\Pricing\Price\CatalogRulePrice;
use Magento\Framework\Pricing\Price\BasePriceProviderInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pins the behaviour of {@see PriceCalculator::calculateCustomOptionPrice()}, the active (Hyva) replacement for the
 * deprecated core Magento\Catalog\Pricing\Price\CalculateCustomOptionCatalogRule (see magento/magento2#21879).
 *
 * The asserts below mirror the core contract so that any upstream change to how catalog price rules apply to custom
 * options - or to the PriceModifierInterface / BasePriceProviderInterface contracts these depend on - is caught here.
 */
class PriceCalculatorTest extends TestCase
{
    public function testCalculateCustomOptionPriceReturnsFixedOptionPriceWithoutCatalogRuleDiscount(): void
    {
        $product = $this->createProductWithPrices(100.00, 100.00);
        // No catalog rule: modifyPrice() returns the price unchanged.
        $calculator = $this->makeCalculator(static fn (float $price): float => $price);

        self::assertSame(12.50, $calculator->calculateCustomOptionPrice($product, 12.50, false));
    }

    public function testCalculateCustomOptionPriceReturnsPercentOptionPriceWithoutCatalogRuleDiscount(): void
    {
        $product = $this->createProductWithPrices(100.00, 100.00);
        $calculator = $this->makeCalculator(static fn (float $price): float => $price);

        // 15% of the regular price (100) with no rule applied.
        self::assertSame(15.00, $calculator->calculateCustomOptionPrice($product, 15.00, true));
    }

    public function testCalculateCustomOptionPriceDiscountsFixedOptionPriceWhenCatalogRuleApplies(): void
    {
        $product = $this->createProductWithPrices(100.00, 100.00);
        // 20%-off catalog price rule: regular 100 -> 80, total 110 -> 88.
        $calculator = $this->makeCalculator(static fn (float $price): float => round($price * 0.8, 2));

        // Rule applies (80 < 100): (100 + 10) * 0.8 - 80 = 8.
        self::assertSame(8.00, $calculator->calculateCustomOptionPrice($product, 10.00, false));
    }

    public function testCalculateCustomOptionPriceDiscountsPercentOptionPriceWhenCatalogRuleApplies(): void
    {
        $product = $this->createProductWithPrices(100.00, 100.00);
        // Same 20%-off catalog rule, applied to a 20% option price.
        $calculator = $this->makeCalculator(static fn (float $price): float => round($price * 0.8, 2));

        // Option = 20% of 100 = 20; rule applies: (100 + 20) * 0.8 - 80 = 16.
        self::assertSame(16.00, $calculator->calculateCustomOptionPrice($product, 20.00, true));
    }

    public function testCalculateCustomOptionPriceUsesLowestBasePriceWhenDecidingWhetherTheRuleApplies(): void
    {
        // Regular 100 with a 15%-off catalog rule (-> 85), but a special price of 80 already undercuts the rule.
        // The lowest base price must win, so the rule discount must NOT be stacked onto the option price.
        $product = $this->makeProduct(100.00, [
            new TestBasePrice('special_price', 80.00),
            new TestBasePrice('base_price', 100.00),
        ], 100.00);
        $calculator = $this->makeCalculator(static fn (float $price): float => round($price * 0.85, 2));

        // 85 < 80 is false -> no discount, the fixed option price is returned untouched.
        self::assertSame(5.00, $calculator->calculateCustomOptionPrice($product, 5.00, false));
    }

    public function testCalculateCustomOptionPriceIgnoresCatalogRuleAndInvalidPricesWhenSelectingBasePrice(): void
    {
        // The catalog rule price (itself a BasePriceProviderInterface!), a price whose value is false, and a
        // non-base price must all be ignored when choosing the base price. Otherwise the base would drop below the
        // rule price and the legitimate discount would be wrongly skipped. This is the core magento2#21879 fix.
        $product = $this->makeProduct(100.00, [
            new TestBasePrice(CatalogRulePrice::PRICE_CODE, 85.00), // excluded: catalog rule price code
            new TestBasePrice('special_price', false),             // excluded: value === false
            new TestPrice('msrp', 1.00),                           // excluded: not a base price provider
            new TestBasePrice('base_price', 95.00),                // the only valid base price
        ], 100.00);
        // 10%-off catalog rule: regular 100 -> 90, total 110 -> 99.
        $calculator = $this->makeCalculator(static fn (float $price): float => round($price * 0.9, 2));

        // 90 < 95 -> rule applies: (100 + 10) * 0.9 - 90 = 9.
        self::assertSame(9.00, $calculator->calculateCustomOptionPrice($product, 10.00, false));
    }

    public function testCalculateCustomOptionPriceFallsBackToProductPriceWhenNoBasePriceProvidersExist(): void
    {
        // No usable BasePriceProviderInterface entries -> the base price must fall back to Product::getPrice().
        $product = $this->makeProduct(100.00, [
            new TestPrice('msrp', 1.00),               // not a base price provider
            new TestBasePrice('special_price', false), // base provider but no value
        ], 100.00);
        // 20%-off catalog rule: regular 100 -> 80, total 110 -> 88.
        $calculator = $this->makeCalculator(static fn (float $price): float => round($price * 0.8, 2));

        // Fallback base price = Product::getPrice() = 100; 80 < 100 -> rule applies: (100 + 10) * 0.8 - 80 = 8.
        self::assertSame(8.00, $calculator->calculateCustomOptionPrice($product, 10.00, false));
    }

    private function createProductWithPrices(float $regularPrice, float $basePrice): Product
    {
        return $this->makeProduct(
            $regularPrice,
            [
                new TestBasePrice(CatalogRulePrice::PRICE_CODE, 80.00),
                new TestBasePrice('base_price', $basePrice),
            ],
            $basePrice
        );
    }

    /**
     * Build a product whose price info exposes the given regular price and the given list of price objects, and
     * whose Product::getPrice() (the base-price fallback) returns $fallbackPrice.
     *
     * @param PriceInterface[] $prices
     */
    private function makeProduct(float $regularPrice, array $prices, float $fallbackPrice): Product
    {
        $regular = new TestPrice(RegularPrice::PRICE_CODE, $regularPrice);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->with(RegularPrice::PRICE_CODE)->willReturn($regular);
        $priceInfo->method('getPrices')->willReturn($prices);

        $product = $this->createMock(Product::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getPrice')->willReturn($fallbackPrice);
        $product->method('getId')->willReturn(123);

        return $product;
    }

    /**
     * @param callable(float):float $modifier emulates PriceModifierInterface::modifyPrice (catalog rule application)
     */
    private function makeCalculator(callable $modifier): PriceCalculator
    {
        $priceModifier = $this->createMock(PriceModifierInterface::class);
        $priceModifier->method('modifyPrice')->willReturnCallback($modifier);

        return new PriceCalculator($this->createMock(PriceCurrencyInterface::class), $priceModifier);
    }
}

class TestPrice implements PriceInterface
{
    public function __construct(private string $priceCode, private float|false $value)
    {
    }

    public function getPriceCode()
    {
        return $this->priceCode;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getAmount()
    {
        throw new \BadMethodCallException('Not needed for this test.');
    }

    public function getCustomAmount($amount = null, $exclude = null, $context = [])
    {
        throw new \BadMethodCallException('Not needed for this test.');
    }
}

class TestBasePrice extends TestPrice implements BasePriceProviderInterface
{
}
