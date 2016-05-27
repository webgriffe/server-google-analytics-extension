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
     * Il tracciamento degli ordini viene fatto solo lato server al momento del pay() della fattura
     *
     * @return string
     */
    protected function _getOrdersTrackingCode()
    {
        return '';
    }
}
