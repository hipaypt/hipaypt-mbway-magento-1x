<?php

/**
 * @author hipay.pt
 */
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayClient.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayRequest.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayRequestTransaction.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayRequestDetails.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayRequestResponse.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayRequestDetailsResponse.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayRequestTransactionResponse.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayPaymentDetailsResult.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayRequestRefund.php');
include_once(Mage::getBaseDir('app') . '/code/local/Hipay/Mbway/lib/MbwayNotification.php');

use HipayMbway\MbwayClient;
use HipayMbway\MbwayRequest;
use HipayMbway\MbwayRequestTransaction;
use HipayMbway\MbwayRequestDetails;
use HipayMbway\MbwayRequestResponse;
use HipayMbway\MbwayRequestDetailsResponse;
use HipayMbway\MbwayRequestTransactionResponse;
use HipayMbway\MbwayPaymentDetailsResult;
use HipayMbway\MbwayRequestRefund;
use HipayMbway\MbwayNotification;

class Hipay_Mbway_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract {

    protected $_code = 'hipaymbway';
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canRefund = false;
    protected $_canVoid = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = true;
    protected $_canSaveCc = false;
    protected $_error = "";
    protected $_infoBlockType = 'hipaymbway/info';
    protected $_order;
    protected $_config;
    protected $_payment;
    protected $_redirectUrl;
    private $_username = "";
    private $_password = "";
    private $_category = "";
    private $_debugMode = false;
    private $_entity = "";
    private $_reference = "";
    private $_minAmount = "";
    private $_maxAmount = "";
    private $_amount = "";
    private $_orderID = "";
    private $_status = "";

    /*
     * getOrderPlaceRedirectUrl
     */

    public function getOrderPlaceRedirectUrl() {
        
    }

    /*
     * _getOrder
     */

    protected function _getOrder() {
        return $this->_order;
    }

    /*
     * _getRef
     */

    public function getRef($orderId) {

        $this->_order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        $this->_payment = $this->_order->getPayment();
        $response->_mbwayReference = $this->_payment->getMbwayReference();
        $response->_mbwayStatus = $this->_payment->getMbwayStatus();
        $response->_mbwayPhone = $this->_payment->getMbwayPhone();
        return $response;
    }

    /*
     * authorize
     */

    public function authorize(Varien_Object $payment, $amount) {

        $systemConfig = Mage::getStoreConfig('payment/hipaymbway');
        $this->_username = $systemConfig['hipaymbway_username'];
        $this->_password = $systemConfig['hipaymbway_password'];
        $this->_debugMode = $systemConfig['hipaymbway_debug'];
        $this->_category = $systemConfig['hipaymbway_category'];
        $this->_entity = $systemConfig['hipaymbway_entity'];
        $this->_minAmount = $systemConfig['min_order_total'];
        $this->_maxAmount = $systemConfig['max_order_total'];

        if (empty($this->_order)) {
            $this->_order = $payment->getOrder();
        }

        if (empty($this->_payment)) {
            $this->_payment = $payment;
        }

        $order = $this->_getOrder();
        $billingAddress = $order->getBillingAddress();
        $email = $order->getCustomerEmail();
        $mobile = $billingAddress->getTelephone();
        $this->_orderID = $order->getIncrementId();

        $this->generateMbwayPayment($amount, $email, $mobile);

        if ($this->_status == 'vp1') {
            $this->updatePaymentTable($mobile);
            Mage::getSingleton('core/session')->addSuccess(__('Please, open your MB WAY App and accept the transaction.'));
        } else {
            Mage::throwException($this->_error);
        }
    }

    /*
     * generateMbwayPayment
     */

    function generateMbwayPayment($amount, $email, $mobile) {
        try {
            if (Mage::getStoreConfig('web/seo/use_rewrites')) {
                $callback_url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . 'hipaymbway/index/notification?ref=' . $this->_orderID;
            } else {
                $callback_url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . 'index.php/hipaymbway/index/notification?ref=' . $this->_orderID;
            }
            if ($this->_debugMode == 1) {
                $this->_debugMode = true;
            } else {
                $this->_debugMode = false;
            }
            $mbway = new MbwayClient($this->_debugMode);

            $mbwayRequestTransaction = new MbwayRequestTransaction($this->_username, $this->_password, $amount, $mobile, $email, $this->_orderID, $this->_category, $callback_url, $this->_entity);
            $mbwayRequestTransaction->set_description($this->_orderID);
            $mbwayRequestTransaction->set_clientVATNumber("");
            $mbwayRequestTransaction->set_clientName("");

            $mbwayRequestTransactionResult = new MbwayRequestTransactionResponse($mbway->createPayment($mbwayRequestTransaction)->CreatePaymentResult);
            if ($mbwayRequestTransactionResult->get_Success() && $mbwayRequestTransactionResult->get_ErrorCode() == "0") {
                switch ($mbwayRequestTransactionResult->get_MBWayPaymentOperationResult()->get_StatusCode()) {
                    case "vp1":
                        $this->_reference = $mbwayRequestTransactionResult->get_MBWayPaymentOperationResult()->get_OperationId();
                        $this->_status = "vp1";
                        break;
                    case "vp2":
                        $this->_status = "vp2";
                        break;
                    case "vp3":
                        $this->_status = "vp3";
                        break;
                    case "er1":
                        $this->_status = "er1";
                        break;
                    case "er2":
                        $this->_status = "er2";
                        break;
                    default:
                        $this->_status = $mbwayRequestTransactionResult->get_MBWayPaymentOperationResult()->get_StatusCode();
                        $this->_error = "Operation refused. Please try again or choose another payment method.";
                        break;
                }
            } else {
                $this->_error = $mbwayRequestTransactionResult->get_ErrorDescription();
                return false;
            }
        } catch (Exception $e) {
            $this->_error = $e->getMessage();
            return false;
        }

        return true;
    }

   function updatePaymentTable($mobile){
        $this->_payment->setMbwayReference($this->_reference);
        $this->_payment->setMbwayStatus($this->_status);
        $this->_payment->setMbwayPhone($mobile);

        if (!$this->isAdmin()){
                $session = Mage::getSingleton('checkout/session');
        } else {
                $session = Mage::getSingleton('adminhtml/session_quote');
                $session->clear();
        }
        $paymentQuote = $session->getQuote()->getPayment();
        $paymentQuote->setMbwayReference($this->_reference);
        $paymentQuote->setMbwayStatus($this->_status);
        $paymentQuote->setMbwayPhone($mobile);
        $paymentQuote->save();

        $this->_order->save();
        return;
   }


    private function isAdmin() {
        if (Mage::app()->getStore()->isAdmin()) {
            return true;
        }

        if (Mage::getDesign()->getArea() == 'adminhtml') {
            return true;
        }

        return false;
    }

}

?>
