<?php
/**
 * Created by PhpStorm.
 * User: andrea
 * Date: 27/05/16
 * Time: 16.11
 */

class Webgriffe_ServerGoogleAnalytics_Helper_Data extends Mage_Core_Helper_Abstract
{
    const LOG_FILENAME                                  = 'Webgriffe_ServerGoogleAnalytics.log';

    const XML_PATH_SERVERGOOGLEANALYTICS_ENABLED        = 'google/servergoogleanalytics/enabled';

    const GA_ALREADY_SENT_ADDITIONAL_INFORMATION_KEY    = 'ga_already_sent';
    const GA_CLIENT_ID_ADDITIONAL_INFORMATION_KEY       = 'ga_client_id';

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_SERVERGOOGLEANALYTICS_ENABLED);
    }

    /**
     * @param Mage_Sales_Model_Order $order
     */
    public function trackConversion(Mage_Sales_Model_Order $order)
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->log("Requested server to server tracking for order '{$order->getIncrementId()}'");
        try {
            if ($this->checkGaAlreadySent($order)) {
                $this->log("Google analytics tracking already sent for order '{$order->getIncrementId()}'");
                return;
            }

            $this->log("Google analytics tracking not yet sent for order '{$order->getIncrementId()}'");

            if (!$this->isGaUniversalTrackingActive()) {
                $this->log('Google analytics universal tracking is disabled or not configured');
                return;
            }

            $this->log("Before tracking transaction for order '{$order->getIncrementId()}'");
            if (!$this->trackConversionGaUniversal($order)) {
                $this->log("Could not track order '{$order->getIncrementId()}'", Zend_Log::ERR);
                return;
            }

            $this->log("Transaction tracked for order '{$order->getIncrementId()}' with GA universal");

            $this->setGaAlreadySent($order);

            $this->log("Transaction for order '{$order->getIncrementId()}' marked as already tracked");

        } catch (Exception $ex) {
            //Un errore qui non deve influenzare il workflow dell'ordine. Quindi logghiamo tutto e non rilanciamo
            //l'eccezione
            $this->log("Exception while trying to track order '{$order->getIncrementId()}'", Zend_Log::CRIT);
            $this->log($ex->getMessage(), Zend_Log::CRIT);
            $this->log($ex->getTraceAsString(), Zend_Log::CRIT);
        }
    }

    /**
     * @return bool
     */
    protected function isGaUniversalTrackingActive()
    {
        return Mage::getStoreConfigFlag('google/analytics/active') &&
            Mage::getStoreConfig('google/analytics/type') == Mage_GoogleAnalytics_Helper_Data::TYPE_UNIVERSAL &&
            Mage::getStoreConfig('google/analytics/account');
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @return bool
     */
    protected function trackConversionGaUniversal(Mage_Sales_Model_Order $order)
    {
        $accountNumber = Mage::getStoreConfig('google/analytics/account');

        $config = array();
        $client = Krizon\Google\Analytics\MeasurementProtocol\MeasurementProtocolClient::factory($config);
        $cid = $this->getClientId($order);
        if (empty($cid)) {
            $this->log("Could not track order '{$order->getIncrementId()}': client id not available", Zend_Log::ERR);
            return false;
        }

        //Remove "GA1.2." from the beginning of the cookie content
        $cid = preg_replace('/^GA1\.2\./', '', $cid);

        $params = array(
            'tid' => $accountNumber,                                // Tracking ID / Property ID.
            'cid' => $cid,                                          // Anonymous Client ID.
            'aip' => (int)Mage::getStoreConfigFlag('google/analytics/anonymization'),

            'ti' => $order->getIncrementId(),                       // transaction ID. Required.
            'ta' => Mage::app()->getStore()->getName(),             // Transaction affiliation.
            'tr' => $order->getBaseGrandTotal(),                    // Transaction revenue.
            'ts' => $order->getBaseShippingAmount(),                // Transaction shipping.
            'tt' => $order->getBaseTaxAmount(),                     // Transaction tax.
            'cu' => $order->getBaseCurrencyCode(),                  // Currency code.
        );

        $this->log('Transaction params: '.print_r($params, true));

        /** @var \Guzzle\Http\Message\Response $response */
        $response = $client->transaction($params);

        $this->log('Transaction response: '.$response->getBody(true));

        /** @var Mage_Sales_Model_Order_Item $item */
        foreach ($order->getAllVisibleItems() as $item) {
            $params = array(
                'tid' => $accountNumber,                        // Tracking ID / Property ID.
                'cid' => $cid,                                  // Anonymous Client ID.
                'aip' => (int)Mage::getStoreConfigFlag('google/analytics/anonymization'),

                'ti' => $order->getIncrementId(),               // transaction ID. Required.
                'in' => $item->getName(),                       // Item name. Required.
                'ip' => $item->getBasePrice(),                  // Item price.
                'iq' => $item->getQtyOrdered(),                 // Item quantity.
                'ic' => $item->getSku(),                        // Item code / SKU.
                'cu' => $order->getBaseCurrencyCode(),          // Currency code.
                'iv' => $this->getCategory($item),              // Item variation / category.
            );

            $this->log("Item {$item->getId()} params: ".print_r($params, true));

            $response = $client->item($params);

            $this->log("Item {$item->getId()} response: ".$response->getBody(true));
        }

        return true;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @return bool
     */
    public function checkGaAlreadySent(Mage_Sales_Model_Order $order)
    {
        return $order->getPayment()->getAdditionalInformation(self::GA_ALREADY_SENT_ADDITIONAL_INFORMATION_KEY);
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param bool $value
     * @return Mage_Core_Model_Abstract
     */
    public function setGaAlreadySent(Mage_Sales_Model_Order $order, $value = true)
    {
        return $order->getPayment()->setAdditionalInformation(self::GA_ALREADY_SENT_ADDITIONAL_INFORMATION_KEY, $value)
            ->save();
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @return string
     */
    public function getClientId(Mage_Sales_Model_Order $order)
    {
        return $order->getPayment()->getAdditionalInformation(self::GA_CLIENT_ID_ADDITIONAL_INFORMATION_KEY);
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param $value
     * @return Mage_Core_Model_Abstract
     */
    public function setClientId(Mage_Sales_Model_Order $order, $value)
    {
        return $order->getPayment()->setAdditionalInformation(self::GA_CLIENT_ID_ADDITIONAL_INFORMATION_KEY, $value)
            ->save();
    }

    protected function getCategory(Mage_Sales_Model_Order_Item $item)
    {
        $product = Mage::getModel('catalog/product')->load($item->getProductId());
        if (!$product) {
            return null;
        }

        $catIds = $product->getCategoryIds();
        foreach ($catIds as $catId) {
            $category = Mage::getModel('catalog/category')->load($catId);
            if ($category) {
                return $category->getName();
            }
        }

        return false;
    }

    public function log($message, $level = Zend_Log::DEBUG, $forceLog = false)
    {
        Mage::log($message, $level, self::LOG_FILENAME, $forceLog);
    }
}
