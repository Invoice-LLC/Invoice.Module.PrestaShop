<?php
require_once "sdk/RestClient.php";
require_once "sdk/GET_TERMINAL.php";
require_once "sdk/CREATE_TERMINAL.php";
require_once "sdk/CREATE_PAYMENT.php";
require_once "sdk/TerminalInfo.php";
require_once "sdk/PaymentInfo.php";
require_once "sdk/common/ITEM.php";
require_once "sdk/common/SETTINGS.php";

class InvoiceValidationModuleFrontController extends ModuleFrontController
{
    private $terminal;
    /**
     * @var RestClient
     */
    private $restClient;
    private $paymentUrl;

    private $attemptsCount = 0;

    public function initContent()
    {
        parent::initContent();
        $this->setTemplate('module:invoice/views/templates/front/callback.tpl');

        if(!$this->getOrCreateTerminal()) {
            $this->log("Ошибка при создании терминала");
            $this->context->smarty->assign($this->context->smarty->assign('result', "Ошибка при создании терминала"));
        }

        $products = $_GET[1];
        $total = $_GET[0]["total"];

        $id = $_GET["cart"]["id"];
        $id_currency = $_GET["cart"]["id_currency"];
        $id_customer = $_GET["cart"]["id_customer"];
        $secure_key = $_GET["cart"]["secure_key"];
        $id = str_replace("\"", "", $id);
        $this->log("ID: $id");

        $id = (int) $id;
        $this->module->validateOrder((int)$id, Configuration::get('INVOICE_PROCESS'), $total, $this->module->displayName, NULL, array(), (int)$id_currency, false, $secure_key);

        $id_order = Order::getOrderByCartId($id);
        $this->log(json_encode($products));
        if($this->createPayment($products,$total,  $id_order)) {
            $this->context->smarty->assign($this->context->smarty->assign('result', "<script>window.location.replace('".$this->paymentUrl."'); </script>"));
            return Tools::redirect($this->paymentUrl);
        } else {
            $this->log("Не удалось создать платеж");
            $this->context->smarty->assign($this->context->smarty->assign('result', "Ошибка при создании платежа"));
            return "Ошибка при создание платежа";
        }
    }

    protected function isValidOrder()
    {
        return true;
    }

    public function log($log) {
        $logger = new FileLogger(0);
        $logger->setFilename(_PS_ROOT_DIR_."/invoice.log");
        $logger->logDebug($log);
    }

    private function getOrCreateTerminal($createNew = false) {
        $api_key = Configuration::get('INVOICE_API_KEY');
        $login = Configuration::get('INVOICE_LOGIN');

        if($api_key == null or $login == null) {
            return false;
        }

        $this->terminal = Configuration::get('INVOICE_TERMINAL');

        $this->restClient = new RestClient($login, $api_key);
        $get_terminal = new GET_TERMINAL();
        $get_terminal->id = $this->terminal;

        $terminalInfo = $this->restClient->GetTerminal($get_terminal);

        $this->log("Проверка терминала ".json_encode($terminalInfo));

        if($createNew or $terminalInfo == null or (isset($terminalInfo->error) and $terminalInfo->error != null)) {
            $this->log("Создание нового терминала");
            $terminal_name = Configuration::get('INVOICE_TERMINAL_NAME');

            if($terminal_name == null) {
                $terminal_name = "Магазин";
            }

            $create_terminal = new CREATE_TERMINAL($terminal_name);
            $create_terminal->type = "dynamical";
            $create_terminal->description = Configuration::get('INVOICE_TERMINAL_DESC');

            $terminalInfo = $this->restClient->CreateTerminal($create_terminal);

            if($terminalInfo == null or (isset($terminalInfo->error) and $terminalInfo->error != null)) {
                $this->log("Ошибка создания терминала: ".json_encode($terminalInfo));
                return false;
            } else {
                Configuration::updateValue("INVOICE_TERMINAL", $terminalInfo->id);
            }
        }

        return true;
    }

    private function createPayment($products, $total, $cart_id) {
        require_once "sdk/common/INVOICE_ORDER.php";

        $url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $this->log("Создание платежа: ".$cart_id);
        $receipt = array();

        foreach ($products as $product) {
            $item = new ITEM($product["name"],$product["price"],$product["quantity"],$product["total"]);
            array_push($receipt, $item);
        }

        $settings = new SETTINGS($this->terminal);
        $settings->success_url = $url;
        $settings->fail_url = $url;

        $order = new INVOICE_ORDER($total);
        $order->id = "$cart_id";

        $create_payment = new CREATE_PAYMENT($order,$settings,$receipt);

        $this->log("CREATE_PAYMENT: ".json_encode($create_payment));

        $paymentInfo = $this->restClient->CreatePayment($create_payment);
        $this->log("PAYMENT: ".json_encode($paymentInfo));

        if($paymentInfo == null or (isset($paymentInfo->error) and $paymentInfo->error != null)) {
            if(isset($paymentInfo->error) and $paymentInfo->error == 3) {
                if($this->attemptsCount < 2) {
                    $this->attemptsCount++;
                    $this->getOrCreateTerminal(true);
                    return $this->createPayment($products, $total, $cart_id);
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }
        $this->paymentUrl = $paymentInfo->payment_url;

        return true;
    }
}
