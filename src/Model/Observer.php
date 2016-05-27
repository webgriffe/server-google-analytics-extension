<?php

/**
 * Created by PhpStorm.
 * User: andrea
 * Date: 27/05/16
 * Time: 16.53
 */
class Webgriffe_ServerGoogleAnalytics_Model_Observer
{
    const GA_COOKIE_NAME = '_ga';

    /**
     * @var Webgriffe_ServerGoogleAnalytics_Helper_Data $helper
     */
    protected $helper;

    public function __construct()
    {
        $this->helper = Mage::helper('webgriffe_servergoogleanalytics');
    }

    /**
     * Event: sales_order_save_after
     *
     * @param Varien_Event_Observer $observer
     */
    public function saveGaCookieValue(Varien_Event_Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        /** @var Mage_Sales_Model_Order $order */
        $order = $observer->getData('order');
        $clientId = Mage::app()->getRequest()->getCookie(self::GA_COOKIE_NAME, null);
        if ($clientId) {
            $this->helper->log("Client ID: ".$clientId);
            $this->helper->setClientId($order, $clientId);
        } else {
            $this->helper->log("No client ID found");
        }
    }

    /**
     * event: sales_order_invoice_pay
     *
     * @param Varien_Event_Observer $observer
     */
    public function onInvoicePaid(Varien_Event_Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        /** @var Mage_Sales_Model_Order_Invoice $invoice */
        $invoice = $observer->getData('invoice');

        $order = $invoice->getOrder();

        $this->helper->log('Tracking conversion for order '.$order->getIncrementId())
        $this->helper->trackConversion($order);
    }
}
