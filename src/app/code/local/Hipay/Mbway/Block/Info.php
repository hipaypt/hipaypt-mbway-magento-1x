<?php

class Hipay_Mbway_Block_Info extends Mage_Payment_Block_Info {

    protected function _prepareSpecificInformation($transport = null) {
        if (null !== $this->_paymentSpecificInformation) {
            return $this->_paymentSpecificInformation;
        }

        $transport = parent::_prepareSpecificInformation($transport);

        $info = $this->getInfo();
        if ($info instanceof Mage_Sales_Model_Order_Payment) {
            $order = $info->getOrder();
            $orderid = $order->getData('increment_id');
            $payment_method_code = $order->getPayment()->getMethodInstance()->getCode();
            if ($payment_method_code == "hipaymbway") {
                $hipaymbway = Mage::getModel('hipaymbway/paymentmethod');
                $hipaymbway_values = $hipaymbway->getRef($orderid);
                echo "<br><b>" . __("Reference") . ":</b> " . $hipaymbway_values->_mbwayReference . "<br><b>" . __("Mobile Number") . ":</b> " . $hipaymbway_values->_mbwayPhone . "<br>Status Code: " . $hipaymbway_values->_mbwayStatus;
                $transport->setData($hipaymbway_values);
            }
        }

        return $transport;
    }

}
