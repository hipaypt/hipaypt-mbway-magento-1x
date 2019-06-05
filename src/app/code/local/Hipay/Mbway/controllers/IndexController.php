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

class Hipay_Mbway_IndexController extends Mage_Core_Controller_Front_Action
{

    protected $_order;
    protected $_config;
    protected $_payment;

	private $_mbwayReference;
	private $_mbwayStatus;
	private $_mbwayPhone;
    private $_username 		= ""; 
    private $_password 		= ""; 
    private $_category 		= ""; 
    private $_debugMode 	= false;
    private $_entity 		= "";
    private $_reference 	= "";
    private $_minAmount 	= "";
    private $_maxAmount 	= "";
    private $_amount 		= "";
    private $_orderID 		= "";
    private $_status 		= "";	
   
    public function notificationAction()
    {

		$notification = Mage::app()->getRequest()->getParams();
		if (!isset($notification['ref'])){
			exit;
		}

		$ref = (int)$notification['ref'];
		$notification = print_r($notification,true);
		Mage::log($notification,null,'hipay_mbway_'.date('Ymd').'.log',true);

		$this->_order = Mage::getModel('sales/order')->loadByIncrementId($ref);
		$this->_payment =  $this->_order->getPayment();

		$this->_mbwayReference = $this->_payment->getMbwayReference();
		$this->_mbwayStatus = $this->_payment->getMbwayStatus();
		$this->_mbwayPhone = $this->_payment->getMbwayPhone();
	
        $systemConfig = Mage::getStoreConfig('payment/hipaymbway');  
        $this->_username = $systemConfig['hipaymbway_username'];
        $this->_password = $systemConfig['hipaymbway_password'];
        $this->_debugMode = $systemConfig['hipaymbway_debug'];
        $this->_category = $systemConfig['hipaymbway_category'];
        $this->_entity = $systemConfig['hipaymbway_entity'];
        $this->_minAmount = $systemConfig['min_order_total'];
        $this->_maxAmount = $systemConfig['max_order_total'];		
	
		try {
				
			$mbway = new MbwayClient($this->_debugMode);
			$mbwayRequestDetails = new MbwayRequestDetails($this->_username, $this->_password, $this->_mbwayReference, $this->_entity);

			$mbwayRequestDetailsResult = new MbwayRequestDetailsResponse($mbway->getPaymentDetails($mbwayRequestDetails)->GetPaymentDetailsResult);
			if ($mbwayRequestDetailsResult->get_ErrorCode() <> 0 || !$mbwayRequestDetailsResult->get_Success()) {
				echo __("Notification: Unable to confirm payment status.");
				exit;
			}

			$detailStatusCode = $mbwayRequestDetailsResult->get_MBWayPaymentDetails()->get_StatusCode();
			$detailAmount = $mbwayRequestDetailsResult->get_MBWayPaymentDetails()->get_Amount();
			$detailOperationId = $mbwayRequestDetailsResult->get_MBWayPaymentDetails()->get_OperationId();
			
			if ($detailOperationId != $this->_mbwayReference) {
				echo __("Notification: Transaction ID does not match.");
				exit;
			}
			
			echo $detailStatusCode . $this->_mbwayStatus;
		
			if ($detailStatusCode == $this->_mbwayStatus) {
				echo __("Notification: Same Status: " . $detailStatusCode);
				exit;
			}
			
			switch ($detailStatusCode) {
				case "c1":

					$order = Mage::getModel('sales/order',$this->_order); 
					$orderPayment = Mage::getModel('sales/order_payment')->setMbwayStatus($detailStatusCode);
					$order->setPayment($orderPayment)->save();

					if(!$this->_order->canInvoice())
					{
						Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
					}

						 
					$invoice = Mage::getModel('sales/service_order', $this->_order)->prepareInvoice();
						 
					if (!$invoice->getTotalQty()) {
						Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
					}
					$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
					$invoice->register();
					$transactionSave = Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder());
						 
					$transactionSave->save();			
					break;
				case "c3":
				case "c6":
				case "vp1":
					echo __('Waiting capture notification');
					$order = Mage::getModel('sales/order',$this->_order); 
					$orderPayment = Mage::getModel('sales/order_payment')->setMbwayStatus($detailStatusCode);
					$order->setPayment($orderPayment)->save();

					break;
				case "ap1":
					echo __('Refunded');
					$order = Mage::getModel('sales/order',$this->_order); 
					$orderPayment = Mage::getModel('sales/order_payment')->setMbwayStatus($detailStatusCode);
					$order->setPayment($orderPayment)->save();

					$this->_order->addStatusHistoryComment(Mage::helper('hipaymbway')->__('Refund notification received from HiPay'), 	Mage_Sales_Model_Order::STATE_CLOSED);
		        	$this->_order->save();					
		        		        	
					break;
				case "c2":
				case "c4":
				case "c5":
				case "c7":
				case "c8":
				case "c9":
				case "vp2":
					echo __("MB WAY payment cancelled.");
					$order = Mage::getModel('sales/order',$this->_order); 
					$orderPayment = Mage::getModel('sales/order_payment')->setMbwayStatus($detailStatusCode);
					$order->setPayment($orderPayment)->save();

		        	$this->_order->cancel();
					$this->_order->addStatusHistoryComment(Mage::helper('hipaymbway')->__('Cancelled notification received from HiPay'), 	Mage_Sales_Model_Order::STATE_CANCELED);
		        	$this->_order->save();					

					break;
			}
		} catch (Mage_Core_Exception $e) {
			Mage::log($e->getMessage(),null,'hipay_mbway_'.date('Ymd').'.log',true);
			return false;
		}		
    }

    public function indexAction()
    {
        exit;
    }

}
?>
