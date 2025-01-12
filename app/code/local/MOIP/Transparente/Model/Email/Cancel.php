<?php

class MOIP_Transparente_Model_Email_Cancel extends Mage_Core_Model_Email_Template
{
    const ENTITY = 'order';
    const EMAIL_EVENT_NAME_NEW_ORDER_CANCEL = 'moip_cancel';
    const XML_PATH_EMAIL_ENABLED = 'sales_email/order_moip_cancel/enabled';
    const XML_PATH_EMAIL_TEMPLATE = 'sales_email/order_moip_cancel/template';
    const XML_PATH_EMAIL_GUEST_TEMPLATE = 'sales_email/order_moip_cancel/guest_template';
    const XML_PATH_EMAIL_IDENTITY = 'sales_email/order_moip_cancel/identity';
    const XML_PATH_EMAIL_COPY_TO = 'sales_email/order_moip_cancel/copy_to';
    const XML_PATH_EMAIL_COPY_METHOD = 'sales_email/order_moip_cancel/copy_method';

    public function getLinkReorder($order)
    {
        $orderid = $order->getId();

        if ($order->getCustomerIsGuest()) {
            return Mage::getUrl('sales/guest/reorder', array('order_id' => $order->getId()));
        }

        return Mage::getUrl('sales/order/reorder', array('order_id' => $order->getId()));
    }

    public function sendEmail(Varien_Object $order, $moip_details = "Indefinido")
    {
        $email = $order->getCustomerEmail();
        $fName = $order->getCustomerFirstname();
        $lName = $order->getCustomerLastname();
        $storeId = $order->getStoreId();

        $customer = Mage::getModel('customer/customer')->load($order->getCustomerId());
        $cancel_details = Mage::helper('transparente')->__($moip_details);
        $appEmulation = Mage::getSingleton('core/app_emulation');
        $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation($storeId);

        try {
            $paymentBlock = Mage::helper('payment')->getInfoBlock($order->getPayment())->setIsSecureMode(true);
            $paymentBlock->getMethod()->setStore($storeId);
            $paymentBlockHtml = $paymentBlock->toHtml();
        } catch (Exception $exception) {
            $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);
            throw $exception;
        }

        $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);
        $emailTemplateVariables = array(
            'order' => $order,
            'store' => Mage::getModel('core/store')->load($order->getStoreId()),
            'customer' => $customer,
            'details' => $cancel_details,
            'link_reorder' => $this->getLinkReorder($order),
            'payment_html' => $paymentBlockHtml
        );

        $this->sendCancelOrderEmail($email, $fName, $emailTemplateVariables, $storeId);

        return $this;
    }

    public function sendCancelOrderEmail($customerEmail, $customerName, $emailTemplateVariables = array(), $storeId = null)
    {
        $copyTo = $this->_getExplodeEmails(self::XML_PATH_EMAIL_COPY_TO);
        $copyMethod = Mage::getStoreConfig(self::XML_PATH_EMAIL_COPY_METHOD, $storeId);
        $emailInfo = Mage::getModel('core/email_info');
        $emailInfo->addTo($this->getCustomerEmail(), $customerName);
        $templateId = Mage::getStoreConfig(self::XML_PATH_EMAIL_TEMPLATE, $storeId);
        $mailer = Mage::getModel('core/email_template_mailer');
        $emailInfo = Mage::getModel('core/email_info');
        $emailInfo->addTo($customerEmail, $customerName);

        if ($copyTo && $copyMethod == 'bcc') {
            foreach ($copyTo as $email) {
                $emailInfo->addBcc($email);
            }
        }

        $mailer->addEmailInfo($emailInfo);

        if ($copyTo && $copyMethod == 'copy') {
            foreach ($copyTo as $email) {
                $emailInfo = Mage::getModel('core/email_info');
                $emailInfo->addTo($email);
                $mailer->addEmailInfo($emailInfo);
            }
        }

        $mailer->setSender(Mage::getStoreConfig(self::XML_PATH_EMAIL_IDENTITY, $storeId));
        $mailer->setStoreId($storeId);

        if (is_numeric($templateId)) {
            $mailer->setTemplateId($templateId);
        } else {
            $localeCode = Mage::getStoreConfig('general/locale/code', $storeId);
            $templateId = Mage::getModel('core/email_template')->loadDefault('sales_email_order_moip_cancel', $localeCode)->getId();
            $mailer->setTemplateId($templateId);
        }

        $mailer->setTemplateParams($emailTemplateVariables);
        $emailQueue = Mage::getModel('core/email_queue');
        $emailQueue->setEntityId($this->getId())
            ->setEntityType(self::ENTITY)
            ->setEventType(self::EMAIL_EVENT_NAME_NEW_ORDER_CANCEL)
            ->setIsForceCheck(0);

        $mailer->setQueue($emailQueue)->send();

        return $this;
    }

    public function _getExplodeEmails($configPath)
    {
        $data = Mage::getStoreConfig($configPath, $this->getStoreId());

        if (!empty($data)) {
            return explode(',', $data);
        }

        return false;
    }
}
