<?php


class InvoiceCallbackModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->callback();
    }

    public function callback() {
        $this->setTemplate('module:invoice/views/templates/front/callback.tpl');
        $postData = file_get_contents('php://input');
        $notification = json_decode($postData, true);

        if(!isset($notification['id'])) {
            return;
        }

        $type = $notification["notification_type"];
        $id =  strstr($notification["order"]["id"], "-", true);

        $signature = $notification["signature"];

        $api_key = Configuration::get('INVOICE_API_KEY');

        if($signature != $this->getSignature($notification["id"], $notification["status"], $api_key)) {
            $this->context->smarty->assign($this->context->smarty->assign('result', "wrong sign"));
            return;
        }

        $history = new OrderHistory();
        $history->id_order = (int)$id;

        if($type == "pay") {
            if($notification["status"] == "successful") {
                $history->changeIdOrderState((int)Configuration::get('INVOICE_SUCCESS'), (int)$id);
                $this->context->smarty->assign($this->context->smarty->assign('result', "successful"));
                return;
            }
            if($notification["status"] == "error") {
                $history->changeIdOrderState((int)Configuration::get('INVOICE_ERROR'),(int) $id);
                $this->context->smarty->assign($this->context->smarty->assign('result', "error"));
                return;
            }
        }
        if($type == "refund") {
            $history->changeIdOrderState((int)Configuration::get('INVOICE_REFUND'), (int)$id);
            $this->context->smarty->assign('result', "refunded");

            return;
        }
        $this->context->smarty->assign($this->context->smarty->assign('result', "null"));
    }

    public function getSignature($id, $status, $key) {
        return md5($id.$status.$key);
    }
}