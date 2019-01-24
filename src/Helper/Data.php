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
    const XML_PATH_SERVERGOOGLEANALYTICS_ACCOUNT        = 'google/servergoogleanalytics/account';
    const XML_PATH_SERVERGOOGLEANALYTICS_METHOD         = 'google/servergoogleanalytics/method';

    const GA_ALREADY_SENT_ADDITIONAL_INFORMATION_KEY    = 'ga_already_sent';
    const GA_CLIENT_ID_ADDITIONAL_INFORMATION_KEY       = 'ga_client_id';

    //Older versions of Magento do not have this constant in Mage_GoogleAnalytics_Helper_Data
    const TYPE_UNIVERSAL = 'universal';

    /**
     * Checks whether the module config is set to allow server side tracking. Does not actually check to make sure that
     * the tracking invormation (account number) is set
     *
     * @return bool
     */
    public function isEnabled($storeId = null)
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_SERVERGOOGLEANALYTICS_ENABLED, $storeId);
    }

    /**
     * @param Mage_Sales_Model_Order $order
     */
    public function trackConversion(Mage_Sales_Model_Order $order)
    {
        if (!$this->isEnabled($order->getStoreId())) {
            return;
        }

        $this->log("Requested server to server tracking for order '{$order->getIncrementId()}'");
        try {
            if ($this->checkGaAlreadySent($order)) {
                $this->log("Google analytics tracking already sent for order '{$order->getIncrementId()}'");
                return;
            }

            $this->log("Google analytics tracking not yet sent for order '{$order->getIncrementId()}'");

            if (!$this->canTrack($order->getStoreId())) {
                $this->log('Server side Google analytics tracking is disabled or not configured');
                return;
            }

            $this->log("Before tracking transaction for order '{$order->getIncrementId()}'");
            if ($this->getMethod($order->getStoreId()) == Webgriffe_ServerGoogleAnalytics_System_Config_Source_Method::ENHANCED_ECOMMERCE_METHOD) {
                $this->log("Tracking with enhanced ecommerce");
                if (!$this->trackConversionEnhancedEcommerce($order)) {
                    $this->log("Could not track order '{$order->getIncrementId()}'", Zend_Log::ERR);
                    return;
                }
            } else {
                $this->log("Tracking with ecommerce");
                if (!$this->trackConversionEcommerce($order)) {
                    $this->log("Could not track order '{$order->getIncrementId()}'", Zend_Log::ERR);
                    return;
                }
            }

            $this->log("Transaction tracked for order '{$order->getIncrementId()}' with GA universal");

            $this->setGaAlreadySent($order);

            $this->log("Transaction for order '{$order->getIncrementId()}' marked as already tracked");

        } catch (Exception $ex) {
            //An error here must not affect the order workflow. So log everything but do not rethrow the exception
            $this->log("Exception while trying to track order '{$order->getIncrementId()}'", Zend_Log::CRIT);
            $this->log($ex->getMessage(), Zend_Log::CRIT);
            $this->log($ex->getTraceAsString(), Zend_Log::CRIT);
        }
    }

    /**
     * @param Mage_Sales_Model_Order $order
     *
     * @return bool
     *
     * @deprecated Use trackConversionEcommerce() instead
     */
    protected function trackConversionGaUniversal(Mage_Sales_Model_Order $order)
    {
        return $this->trackConversionEcommerce($order);
    }

    protected function trackConversionEcommerce(Mage_Sales_Model_Order $order)
    {
        $accountNumber = $this->getGaAccountNumber($order->getStoreId());

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
            'tid' => $accountNumber,                            // Tracking ID / Property ID.
            'cid' => $cid,                                      // Anonymous Client ID.
            'aip' => (int)$this->isAnonymizationActive($order->getStoreId()),

            'ti' => $order->getIncrementId(),                   // transaction ID. Required.
            'ta' => $order->getStore()->getName(),              // Transaction affiliation.
            'tr' => $order->getBaseGrandTotal(),                // Transaction revenue.
            'ts' => $order->getBaseShippingAmount(),            // Transaction shipping.
            'tt' => $order->getBaseTaxAmount(),                 // Transaction tax.
            'cu' => $order->getBaseCurrencyCode(),              // Currency code.
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
                'aip' => (int)$this->isAnonymizationActive($order->getStoreId()),

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
     *
     * @return bool
     */
    protected function trackConversionEnhancedEcommerce(Mage_Sales_Model_Order $order)
    {
        $accountNumber = $this->getGaAccountNumber($order->getStoreId());

        $cid = $this->getClientId($order);
        if (empty($cid)) {
            $this->log("Could not track order '{$order->getIncrementId()}': client id not available", Zend_Log::ERR);
            return false;
        }

        //Remove "GA1.2." from the beginning of the cookie content
        $cid = preg_replace('/^GA1\.2\./', '', $cid);

        $baseUrl = Mage::getStoreConfig('web/secure/base_url', $order->getStoreId());
        $params = array(
            'v'     => 1,                                   // Version.
            'tid'   => $accountNumber,                      // Tracking ID / Property ID.
            'cid'   => $cid,                                // Anonymous Client ID.
            't'     => 'pageview',                          // Pageview hit type.
            'dl'    => $baseUrl,                            // Document hostname.

            'ti'    => $order->getIncrementId(),            // Transaction ID. Required.
            'ta'    => $order->getStore()->getName(),       // Affiliation.
            'tr'    => $order->getBaseGrandTotal(),         // Revenue.
            'tt'    => $order->getBaseTaxAmount(),          // Tax.
            'ts'    => $order->getBaseShippingAmount(),     // Shipping.

            'pa'    => 'purchase',                          // Product action (purchase). Required.
        );

        $index = 1;
        /** @var Mage_Sales_Model_Order_Item $item */
        foreach ($order->getAllVisibleItems() as $item) {
            $params = array_merge(
                $params,
                array(
                    "pr{$index}id" => $item->getSku(),              // Product ID. Either ID or name must be set.
                    "pr{$index}nm" => $item->getName(),             // Product name. Either ID or name must be set.
                    "pr{$index}ca" => $this->getCategory($item),    // Product category.
                    "pr{$index}pr" => $item->getBasePrice(),        // Product price
                    "pr{$index}qt" => $item->getQtyOrdered(),       // Product quantity
                )
            );
            ++$index;
        }

        $this->log('Transaction params: '.print_r($params, true));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.google-analytics.com/collect');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, utf8_encode(http_build_query($params)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $this->log('Transaction response: '.$response->getBody(true));

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
        //Dispatch an event so that it is possible to change which category is used here
        $data = new Varien_Object(array('item' => $item, 'category_name' => null));
        Mage::dispatchEvent(
            'google_analytics_transaction_tracking_order_item_product_category',
            array('data' => $data)
        );
        $categoryName = $data->getData('category_name');
        if ($categoryName) {
            return $categoryName;
        }

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

    /**
     * @return string
     */
    protected function getGaAccountNumber($storeId)
    {
        if ($this->isGaAccountOverrideActive($storeId)) {
            return $this->getGaAccountOverride($storeId);
        } elseif ($this->isNativeGaUniversalActive($storeId)) {
            return $this->getGaUniversalAccount($storeId);
        } elseif ($this->isFoomanGaUniversalActive($storeId)) {
            return $this->getFoomanGaUniversalAccount($storeId);
        }
    }

    /**
     * @return bool
     */
    protected function isAnonymizationActive($storeId)
    {
        if ($this->isGaAccountOverrideActive($storeId)) {
            return true;    //???
        } elseif ($this->isNativeGaUniversalActive($storeId)) {
            return Mage::getStoreConfigFlag('google/analytics/anonymization', $storeId);
        } elseif ($this->isFoomanGaUniversalActive($storeId)) {
            return Mage::getStoreConfigFlag('google/analyticsplus_universal/anonymise', $storeId);
        }
    }

    /**
     * @param $storeId
     * @return bool
     */
    protected function isGaAccountOverrideActive($storeId)
    {
        return strlen($this->getGaAccountOverride($storeId)) > 0;
    }

    /**
     * @param $storeId
     * @return bool
     *
     * @deprecated use canTrack() instead
     */
    protected function isGaUniversalTrackingActive($storeId)
    {
        return $this->isNativeGaUniversalActive($storeId) || $this->isFoomanGaUniversalActive($storeId);
    }

    /**
     * @param $storeId
     * @return bool
     */
    protected function canTrack($storeId)
    {
        return $this->isEnabled($storeId) &&
            ($this->isGaAccountOverrideActive($storeId) ||
            $this->isNativeGaUniversalActive($storeId) ||
            $this->isFoomanGaUniversalActive($storeId));
    }

    /**
     * @param $storeId
     * @return bool
     */
    protected function isNativeGaUniversalActive($storeId)
    {
        return Mage::getStoreConfigFlag('google/analytics/active', $storeId) &&
            Mage::getStoreConfig('google/analytics/type', $storeId) == self::TYPE_UNIVERSAL &&
            $this->getGaUniversalAccount($storeId);
    }

    /**
     * @param $storeId
     * @return bool
     */
    protected function isFoomanGaUniversalActive($storeId)
    {
        return Mage::getStoreConfigFlag('google/analyticsplus_universal/enabled', $storeId) &&
            $this->getFoomanGaUniversalAccount($storeId);
    }

    /**
     * @param $storeId
     * @return string
     */
    protected function getGaAccountOverride($storeId)
    {
        return trim(
            (string)Mage::getStoreConfig(self::XML_PATH_SERVERGOOGLEANALYTICS_ACCOUNT, $storeId)
        );
    }

    /**
     * @param $storeId
     * @return string
     */
    protected function getGaUniversalAccount($storeId)
    {
        return trim((string)Mage::getStoreConfig('google/analytics/account', $storeId));
    }

    /**
     * @param $storeId
     * @return string
     */
    protected function getFoomanGaUniversalAccount($storeId)
    {
        return trim((string)Mage::getStoreConfig('google/analyticsplus_universal/accountnumber', $storeId));
    }

    /**
     * @param $storeId
     * @return mixed
     */
    protected function getMethod($storeId)
    {
        return Mage::getStoreConfig(self::XML_PATH_SERVERGOOGLEANALYTICS_METHOD, $storeId);
    }

    public function log($message, $level = Zend_Log::DEBUG, $forceLog = false)
    {
        Mage::log($message, $level, self::LOG_FILENAME, $forceLog);
    }
}
