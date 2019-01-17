<?php

/**
 * Created by PhpStorm.
 * User: andrea
 * Date: 27/05/16
 * Time: 17.36
 */
class Webgriffe_ServerGoogleAnalytics_Block_GoogleAnalytics_Ga extends Mage_GoogleAnalytics_Block_Ga
{
    /**
     * Order tracking is only performed server-side when the invoice pay() method is called. Therefore the thank you
     * page JS tracking code is disabled
     *
     * @return string
     */
    protected function _getOrdersTrackingCode()
    {
        if (!Mage::helper('webgriffe_servergoogleanalytics')->isEnabled()) {
            return parent::_getOrdersTrackingCode();
        }

        return '';
    }
}
