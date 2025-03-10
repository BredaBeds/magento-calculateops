<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace BredaBeds\CalculateOps\Catalog\Block\Product\View\Options;

use Magento\Catalog\Pricing\Price\BasePrice;
use BredaBeds\CalculateOps\Catalog\Pricing\Price\CalculateCustomOptionCatalogRule;
use Magento\Catalog\Pricing\Price\CustomOptionPriceInterface;
use Magento\Framework\App\ObjectManager;

/**
 * Product options section abstract block.
 *
 * phpcs:disable Magento2.Classes.AbstractApi
 * @api
 * @since 100.0.2
 */
class AbstractOptions extends \Magento\Catalog\Block\Product\View\Options\AbstractOptions
{
    /**
     * Product object
     *
     * @var \Magento\Catalog\Model\Product
     */
    protected $_product;

    /**
     * Product option object
     *
     * @var \Magento\Catalog\Model\Product\Option
     */
    protected $_option;

    /**
     * @var \Magento\Framework\Pricing\Helper\Data
     */
    protected $pricingHelper;

    /**
     * @var \Magento\Catalog\Helper\Data
     */
    protected $_catalogHelper;

    /**
     * @var CalculateCustomOptionCatalogRule
     */
    private $calculateCustomOptionCatalogRule;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Framework\Pricing\Helper\Data $pricingHelper
     * @param \Magento\Catalog\Helper\Data $catalogData
     * @param array $data
     * @param CalculateCustomOptionCatalogRule $calculateCustomOptionCatalogRule
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        \Magento\Catalog\Helper\Data $catalogData,
        array $data = [],
        CalculateCustomOptionCatalogRule $calculateCustomOptionCatalogRule = null
    ) {
        $this->pricingHelper = $pricingHelper;
        $this->_catalogHelper = $catalogData;
        $this->calculateCustomOptionCatalogRule = $calculateCustomOptionCatalogRule
            ?? ObjectManager::getInstance()->get(CalculateCustomOptionCatalogRule::class);
        parent::__construct($context, $pricingHelper, $catalogData, $data);
    }

    /**
     * Set Product object
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return \Magento\Catalog\Block\Product\View\Options\AbstractOptions
     */
    public function setProduct(\Magento\Catalog\Model\Product $product = null)
    {
        $this->_product = $product;
        return $this;
    }

    /**
     * Retrieve Product object
     *
     * @return \Magento\Catalog\Model\Product
     */
    public function getProduct()
    {
        return $this->_product;
    }

    /**
     * Set option
     *
     * @param \Magento\Catalog\Model\Product\Option $option
     * @return \Magento\Catalog\Block\Product\View\Options\AbstractOptions
     */
    public function setOption(\Magento\Catalog\Model\Product\Option $option)
    {
        $this->_option = $option;
        return $this;
    }

    /**
     * Get option
     *
     * @return \Magento\Catalog\Model\Product\Option
     */
    public function getOption()
    {
        return $this->_option;
    }

    /**
     * Retrieve formatted price
     *
     * @return string
     */
    public function getFormattedPrice()
    {
        if ($option = $this->getOption()) {
            return $this->_formatPrice(
                [
                    'is_percent' => $option->getPriceType() == 'percent',
                    'pricing_value' => $option->getPrice($option->getPriceType() == 'percent'),
                ]
            );
        }
        return '';
    }

    /**
     * Retrieve formatted price.
     *
     * @return string
     *
     * @deprecated
     * @see getFormattedPrice()
     */
    public function getFormatedPrice()
    {
        return $this->getFormattedPrice();
    }

    /**
     * Return formatted price
     *
     * @param array $value
     * @param bool $flag
     * @return string
     */
    protected function _formatPrice($value, $flag = true)
    {
        if ($value['pricing_value'] == 0) {
            return '';
        }

        $sign = '+';
        if ($value['pricing_value'] < 0) {
            $sign = '-';
            $value['pricing_value'] = 0 - $value['pricing_value'];
        }

        $priceStr = $sign;

        $customOptionPrice = $this->getProduct()->getPriceInfo()->getPrice('custom_option_price');

        if (!$value['is_percent']) {
            $value['pricing_value'] = $this->calculateCustomOptionCatalogRule->execute(
                $this->getProduct(),
                (float)$value['pricing_value'],
                (bool)$value['is_percent']
            );
        }

        $context = [CustomOptionPriceInterface::CONFIGURATION_OPTION_FLAG => true];
        $optionAmount = $customOptionPrice->getCustomAmount($value['pricing_value'], null, $context);
        $priceStr .= $this->getLayout()->getBlock('product.price.render.default')->renderAmount(
            $optionAmount,
            $customOptionPrice,
            $this->getProduct()
        );

        if ($flag) {
            $priceStr = '<span class="price-notice">' . $priceStr . '</span>';
        }

        return $priceStr;
    }

    /**
     * Get price with including/excluding tax
     *
     * @param float $price
     * @param bool $includingTax
     * @return float
     */
    public function getPrice($price, $includingTax = null)
    {
        if ($includingTax !== null) {
            $price = $this->_catalogHelper->getTaxPrice($this->getProduct(), $price, true);
        } else {
            $price = $this->_catalogHelper->getTaxPrice($this->getProduct(), $price);
        }
        return $price;
    }

    /**
     * Returns price converted to current currency rate
     *
     * @param float $price
     * @return float|string
     */
    public function getCurrencyPrice($price)
    {
        $store = $this->getProduct()->getStore();
        return $this->pricingHelper->currencyByStore($price, $store, false);
    }
}

