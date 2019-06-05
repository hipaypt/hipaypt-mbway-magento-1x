<?php
/**
 * @author hipay.pt
 */

$installer = $this;
$installer->startSetup();
$installer->run("
ALTER TABLE `{$installer->getTable('sales/quote_payment')}` 
ADD `mbway_reference` VARCHAR( 255 ) NOT NULL,
ADD `mbway_phone` VARCHAR( 55 ) NOT NULL,
ADD `mbway_status` VARCHAR( 5 ) NOT NULL;
  
ALTER TABLE `{$installer->getTable('sales/order_payment')}` 
ADD `mbway_reference` VARCHAR( 255 ) NOT NULL,
ADD `mbway_phone` VARCHAR( 55 ) NOT NULL,
ADD `mbway_status` VARCHAR( 5 ) NOT NULL;
");
$installer->endSetup();
