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
     * Il tracciamento degli ordini viene fatto solo lato server al momento del pay() della fattura. Quindi si
     * disabilita il codice javascript per il tracciamento della conversione nella thank you page
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
