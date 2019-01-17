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
     * Here some data is saved in the order payment object, which contains a foreign key toward the order. So it must be
     * done on the order save after event, that is when there is an order in the database that can be referenced.
     *
     * @param Varien_Event_Observer $observer
     */
    public function saveGaCookieValue(Varien_Event_Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        if (Mage::app()->getStore()->isAdmin()) {
            //When saving an order in the admin do nothing. Otherwise the transaction could be tracked for the admin
            //user
            return;
        }

        /** @var Mage_Sales_Model_Order $order */
        $order = $observer->getData('order');
        $clientId = Mage::app()->getRequest()->getCookie(self::GA_COOKIE_NAME, null);
        if ($clientId) {
            //The first user who saves an order is the customer who submits it by clicking the "place order" button
            //in the checkout page. So only set this value the first time and never overwrite it if it is already
            //there.
            if (!$this->helper->getClientId($order)) {
                $this->helper->log("Saving client ID '{$clientId}' on order '{$order->getIncrementId()}'");
                $this->helper->setClientId($order, $clientId);
                $this->helper->log('Saving done');
            }
        } else {
            $this->helper->log("No client ID found for order '{$order->getIncrementId()}'");
        }
    }

    /**
     * event: sales_order_invoice_pay
     *
     * @param Varien_Event_Observer $observer
     */
    public function onInvoicePaid(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Order_Invoice $invoice */
        $invoice = $observer->getData('invoice');

        if (!$this->helper->isEnabled($invoice->getStoreId())) {
            return;
        }

        $order = $invoice->getOrder();

        //The check to make sure not to track the same order twice is done inside the trackConversion() call
        $this->helper->log("Tracking conversion for order '{$order->getIncrementId()}'");
        $this->helper->trackConversion($order);
        $this->helper->log('Tracking done');
    }
}
