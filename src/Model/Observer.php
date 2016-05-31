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
     * Event: sales_order_save_before
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
            if (!$this->helper->getClientId($order)) {
                //Il primo utente che salva un ordine è il cliente che lo crea premendo il pulsante "place order" nel
                //checkout. Quindi non si sovrascrive il client id se un ordine lo ha già.
                $this->helper->log(sprintf('Saving client ID "%s" on order %s', $clientId, $order->getIncrementId()));
                $this->helper->setClientId($order, $clientId);
                $this->helper->log('Saving done');
            }
        } else {
            $this->helper->log(sprintf('No client ID found for order %s', $order->getIncrementId()));
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

        //Il controllo di non aver già fatto il tracciamento per questo ordine viene fatto dentro a trackConversion()
        $this->helper->log('Tracking conversion for order '.$order->getIncrementId());
        $this->helper->trackConversion($order);
        $this->helper->log('Tracking done');
    }
}
