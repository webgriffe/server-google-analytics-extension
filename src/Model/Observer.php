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

    /** @var Webgriffe_ServerGoogleAnalytics_Helper_Data $helper */
    protected $helper;

    public function __construct()
    {
        $this->helper = Mage::helper('webgriffe_servergoogleanalytics');
    }

    /** @param Varien_Event_Observer $observer */
    public function salesOrderSaveAfter(Varien_Event_Observer $observer)
    {
        $this->helper->log("Called %s", __METHOD__);

        if (!$this->helper->isEnabled()) {
            return;
        }

        /** @var Mage_Core_Controller_Request_Http $request */
        $request = Mage::app()->getRequest();

        /** @var Mage_Sales_Model_Order $order */
        $order = $observer->getData('order');
        $clientId = $request->getCookie(self::GA_COOKIE_NAME, null);
        if ($clientId) {
            $this->helper->log("Client ID: ".$clientId);
            $this->helper->setClientId($order, $clientId);
        } else {
            $this->helper->log("No client ID found");
        }

        $paymentStatus  = $request->getParam('payment_status');
        $moduleName     = $request->getModuleName();
        $controllerName = $request->getControllerName();
        $actionName     = $request->getActionName();

        $this->helper->log(sprintf("Force Match: '%s'", $observer->getForceMatch() ? 'Yes' : 'No'));
        $this->helper->log(sprintf("Payment Status: %s", $paymentStatus));
        $this->helper->log(sprintf("Request: '%s/%s/%s'", $moduleName, $controllerName, $actionName));

        if ($observer->getForceMatch() ||
            (
                $moduleName == 'paypal' && $controllerName == 'ipn' && $actionName == 'index' &&
                in_array($paymentStatus, $this->helper->getValidPaymentStates())
            )
        ) {
            $this->helper->trackConversion($order);
        }
    }

    /**
     * Eseguito dopo l'observer nativo di Magento in Mage_GoogleAnalytics_Model_Observer, dato che è dichiarata la
     * dipendenza da quel modulo
     *
     * @param Varien_Event_Observer $observer
     */
    public function setGoogleAnalyticsOnOrderSuccessPageView($observer)
    {
        //@fixme: Se Paypal è in modalità autorizzazione e si vuole tracciare al momento della cattura, nella thank-you
        //page non bisogna tracciare l'ordine
        $this->helper->log("Called %s", __METHOD__);

        if (!$this->helper->isEnabled()) {
            return;
        }

        $orderIds = $observer->getEvent()->getOrderIds();
        $this->helper->log("Order Ids: %s", print_r($orderIds, true));
        if (empty($orderIds) || !is_array($orderIds)) {
            return;
        }
        $processedOrderIds = array();
        foreach ($orderIds as $orderId) {
            $order = Mage::getModel('sales/order')->load($orderId);
            if (!$this->helper->checkGaAlreadySent($order)) {
                $this->helper->setGaAlreadySent($order);
                $processedOrderIds[] = $orderId;
            }
        }
        $this->helper->log("Processed Order Ids: %s", print_r($processedOrderIds, true));
        $block = Mage::app()->getFrontController()->getAction()->getLayout()->getBlock('google_analytics');
        if ($block) {
            $block->setOrderIds($processedOrderIds);
        }
    }
}
