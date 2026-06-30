<?php
declare(strict_types=1);

namespace BredaBeds\CalculateOps\Test\Unit\Plugin;

use BredaBeds\CalculateOps\Plugin\PriceCalculator;
use BredaBeds\CalculateOps\Plugin\ProductOptionPlugin;
use BredaBeds\CalculateOps\Plugin\ProductOptionValuePlugin;
use BredaBeds\CalculateOps\Plugin\ProductPriceViewModelPlugin;
use BredaBeds\CalculateOps\Plugin\SelectTypePlugin;
use Hyva\Theme\ViewModel\ProductPrice;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Type\Select as SelectType;
use Magento\Catalog\Model\Product\Option\Value;
use Magento\Catalog\Pricing\Price\CustomOptionPrice;
use PHPUnit\Framework\TestCase;

class OptionPricingPluginsTest extends TestCase
{
    public function testProductOptionPluginUsesCatalogRuleAwarePriceWhenFinalPriceFlagIsRequested(): void
    {
        $product = $this->createMock(Product::class);
        $calculator = new RecordingPriceCalculator(42.50);
        $plugin = new ProductOptionPlugin($calculator);
        $option = $this->createConfiguredMock(Option::class, [
            'getProduct' => $product,
            'getPriceType' => Value::TYPE_PERCENT,
            'getData' => '15',
        ]);

        $result = $plugin->aroundGetPrice($option, static fn () => 99.00, true);

        self::assertSame(42.50, $result);
        self::assertSame([
            [$product, 15.0, true, 'ProductOptionPlugin'],
        ], $calculator->calls);
    }

    public function testProductOptionPluginFallsBackToCorePriceWhenFinalPriceFlagIsNotRequested(): void
    {
        $calculator = new RecordingPriceCalculator(42.50);
        $plugin = new ProductOptionPlugin($calculator);
        $option = $this->createMock(Option::class);

        $result = $plugin->aroundGetPrice($option, static fn ($flag) => $flag ? 1.0 : 12.25, false);

        self::assertSame(12.25, $result);
        self::assertSame([], $calculator->calls);
    }

    public function testProductOptionValuePluginUsesCatalogRuleAwarePriceWhenFinalPriceFlagIsRequested(): void
    {
        $product = $this->createMock(Product::class);
        $calculator = new RecordingPriceCalculator(33.00);
        $plugin = new ProductOptionValuePlugin($calculator);
        $value = $this->createConfiguredMock(Value::class, [
            'getProduct' => $product,
            'getPriceType' => 'fixed',
            'getData' => '25',
        ]);

        $result = $plugin->aroundGetPrice($value, static fn () => 99.00, true);

        self::assertSame(33.00, $result);
        self::assertSame([
            [$product, 25.0, false, 'ProductOptionValuePlugin'],
        ], $calculator->calls);
    }

    public function testSelectTypePluginCalculatesSingleSelectionOptionPrice(): void
    {
        $product = $this->createMock(Product::class);
        $value = $this->createConfiguredMock(Value::class, [
            'getPrice' => 20.0,
            'getPriceType' => Value::TYPE_PERCENT,
        ]);
        $option = $this->createConfiguredMock(Option::class, [
            'getType' => Option::OPTION_TYPE_DROP_DOWN,
            'getProduct' => $product,
            'getValueById' => $value,
        ]);
        $select = $this->createConfiguredMock(SelectType::class, [
            'getOption' => $option,
        ]);
        $calculator = new RecordingPriceCalculator(18.00);

        $result = (new SelectTypePlugin($calculator))->aroundGetOptionPrice($select, static fn () => 99.00, '7', 100.0);

        self::assertSame(18.00, $result);
        self::assertSame([
            [$product, 20.0, true, 'SelectTypePlugin else'],
        ], $calculator->calls);
    }

    public function testSelectTypePluginSumsMultipleSelectionOptionPrices(): void
    {
        $product = $this->createMock(Product::class);
        $firstValue = $this->createConfiguredMock(Value::class, [
            'getPrice' => 10.0,
            'getPriceType' => 'fixed',
        ]);
        $secondValue = $this->createConfiguredMock(Value::class, [
            'getPrice' => 15.0,
            'getPriceType' => Value::TYPE_PERCENT,
        ]);
        $option = $this->createMock(Option::class);
        $option->method('getType')->willReturn(Option::OPTION_TYPE_CHECKBOX);
        $option->method('getProduct')->willReturn($product);
        $option->method('getValueById')->willReturnMap([
            ['1', $firstValue],
            ['2', $secondValue],
        ]);
        $select = $this->createConfiguredMock(SelectType::class, [
            'getOption' => $option,
        ]);
        $calculator = new RecordingPriceCalculator(8.00, 12.00);

        $result = (new SelectTypePlugin($calculator))->aroundGetOptionPrice($select, static fn () => 99.00, '1,2', 100.0);

        self::assertSame(20.00, $result);
        self::assertSame([
            [$product, 10.0, false, 'SelectTypePlugin foreach'],
            [$product, 15.0, true, 'SelectTypePlugin foreach'],
        ], $calculator->calls);
    }

    public function testHyvaProductPricePluginReturnsFinalAndRegularOptionPrices(): void
    {
        $product = $this->createMock(Product::class);
        $option = $this->createConfiguredMock(Value::class, [
            'getPriceType' => Value::TYPE_PERCENT,
        ]);
        $calculator = new RecordingPriceCalculator(7.25);
        $plugin = new ProductPriceViewModelPlugin($calculator);

        $result = $plugin->afterGetCustomOptionPrice(
            $this->createMock(ProductPrice::class),
            10.00,
            $option,
            CustomOptionPrice::PRICE_CODE,
            $product
        );

        self::assertSame(['final' => 7.25, 'regular' => 10.00], $result);
        self::assertSame([
            [$product, 10.0, true, ''],
        ], $calculator->calls);
    }

    public function testHyvaProductPricePluginLeavesNonCustomOptionPricesUnchanged(): void
    {
        $calculator = new RecordingPriceCalculator(7.25);
        $plugin = new ProductPriceViewModelPlugin($calculator);

        $result = $plugin->afterGetCustomOptionPrice(
            $this->createMock(ProductPrice::class),
            10.00,
            $this->createMock(Value::class),
            'regular_price',
            $this->createMock(Product::class)
        );

        self::assertSame(['final' => 10.00, 'regular' => 10.00], $result);
        self::assertSame([], $calculator->calls);
    }
}

class RecordingPriceCalculator extends PriceCalculator
{
    /** @var array<int, array{0: Product, 1: float, 2: bool, 3: string}> */
    public array $calls = [];

    /** @var float[] */
    private array $results;

    public function __construct(float ...$results)
    {
        $this->results = $results;
    }

    public function calculateCustomOptionPrice(
        Product $product,
        float $optionPrice,
        bool $isPercent,
        string $caller = ''
    ): float {
        $this->calls[] = [$product, $optionPrice, $isPercent, $caller];

        return array_shift($this->results) ?? 0.0;
    }
}
