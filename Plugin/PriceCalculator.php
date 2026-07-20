<?php
declare(strict_types=1);

namespace BredaBeds\CalculateOps\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\ConfiguredPriceInterface;
use Magento\CatalogRule\Pricing\Price\CatalogRulePrice;
use Magento\Framework\Pricing\Price\BasePriceProviderInterface;

class PriceCalculator
{

    // ooedit 2026-07-20 (OOM hotfix): re-entrancy flag, see calculateCustomOptionPrice()
    private bool $calculating = false;

    public function __construct(
        private \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        private \Magento\Catalog\Model\Product\PriceModifierInterface $priceModifier
    ) { }

    /**
     * Calculate custom option price with catalog rules applied
     */
    public function calculateCustomOptionPrice(
        Product $product,
        float $optionPrice,
        bool $isPercent,
        string $caller = ''
    ): float {
        // ooedit 2026-07-20 (OOM hotfix): re-entrancy guard. When this runs while a
        // configured price is being computed (cart/order item context), evaluating the
        // price collection below re-enters getOptionPrice() -> SelectTypePlugin -> here,
        // recursing until memory_limit (the /customer/section/load OOMs since June).
        // On re-entry, fall back to the plain option price — no rule adjustment.
        if ($this->calculating) {
            return $this->calculateOptionPrice($optionPrice, $isPercent, (float)$product->getPrice());
        }
        $this->calculating = true;
        try {
            return $this->doCalculateCustomOptionPrice($product, $optionPrice, $isPercent, $caller);
        } finally {
            $this->calculating = false;
        }
    }

    // ooedit 2026-07-20 (OOM hotfix): original body of calculateCustomOptionPrice, unchanged
    private function doCalculateCustomOptionPrice(
        Product $product,
        float $optionPrice,
        bool $isPercent,
        string $caller = ''
    ): float {
        $regularPrice = (float)$product->getPriceInfo()
            ->getPrice(\Magento\Catalog\Pricing\Price\RegularPrice::PRICE_CODE)
            ->getValue();
            
        $catalogRulePrice = $this->priceModifier->modifyPrice(
            $regularPrice,
            $product
        );
        
        $basePrice = $this->getBasePriceWithoutCatalogRules($product);

        //ooedit: logging
        $logString = sprintf(
            'Price Calculation: Product ID: %s, Regular: %f, Catalog Rule: %f, Base: %f, Option: %f, IsPercent: %d, Caller: %s',
            $product->getId(),
            $regularPrice,
            $catalogRulePrice,
            $basePrice,
            $optionPrice,
            $isPercent ? 1 : 0,
            $caller
        );
        //\BredaBeds\Core\Helper\Notify::printLog($logString);

        // Always calculate the option price
        $finalPrice = $this->calculateOptionPrice($optionPrice, $isPercent, $regularPrice);

        if ($catalogRulePrice < $basePrice) {
            $totalPrice = $regularPrice + $finalPrice;
            $totalWithRules = $this->priceModifier->modifyPrice($totalPrice, $product);
            $finalPrice = $totalWithRules - $catalogRulePrice;
        }

        return $finalPrice;
    }

    private function getBasePriceWithoutCatalogRules(Product $product): float
    {
        $basePrice = null;
        foreach ($product->getPriceInfo()->getPrices() as $price) {
            // ooedit 2026-07-20 (OOM hotfix): skip configured prices — they price the
            // item WITH its custom options (calling getOptionPrice() -> SelectTypePlugin
            // -> calculateCustomOptionPrice -> back here = infinite recursion), and a
            // product+options total is not a base product price, so it does not belong
            // in this min() anyway. ConfiguredRegularPrice extends RegularPrice, which
            // is why the BasePriceProviderInterface check below did not exclude it.
            if ($price instanceof ConfiguredPriceInterface) {
                continue;
            }
            if ($price instanceof BasePriceProviderInterface
                && $price->getPriceCode() !== CatalogRulePrice::PRICE_CODE
                && $price->getValue() !== false
            ) {
                $basePrice = min(
                    $price->getValue(),
                    $basePrice ?? $price->getValue()
                );
            }
        }

        return $basePrice ?? $product->getPrice();
    }

    private function calculateOptionPrice(float $optionPrice, bool $isPercent, float $basePrice): float
    {
        return $isPercent ? $basePrice * $optionPrice / 100 : $optionPrice;
    }
}
