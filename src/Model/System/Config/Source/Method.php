<?php
/**
 * Created by PhpStorm.
 * User: kraken
 * Date: 24/01/19
 * Time: 18.01
 */

class Webgriffe_ServerGoogleAnalytics_Model_System_Config_Source_Method
{
    const ENHANCED_ECOMMERCE_METHOD = 0;
    const ECOMMERCE_METHOD = 1;

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $result = array();
        foreach ($this->toArray() as $value => $label) {
            $result[] = array('value' => $value, 'label' => $label);
        }
        return $result;
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        $helper = Mage::helper('webgriffe_servergoogleanalytics');

        return array(
            self::ENHANCED_ECOMMERCE_METHOD => $helper->__('Enhanced Ecommerce'),
            self::ECOMMERCE_METHOD          => $helper->__('Ecommerce'),
        );
    }
}
