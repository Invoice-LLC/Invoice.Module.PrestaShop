<?php
require_once "sdk/RestClient.php";
require_once "sdk/GET_TERMINAL.php";
require_once "sdk/CREATE_TERMINAL.php";
require_once "sdk/CREATE_PAYMENT.php";
require_once "sdk/TerminalInfo.php";
require_once "sdk/PaymentInfo.php";
require_once "sdk/common/ITEM.php";
require_once "sdk/common/SETTINGS.php";
require_once "sdk/common/INVOICE_ORDER.php";

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

        $api_key = Configuration::get('INVOICE_API_KEY');
        $login = Configuration::get('INVOICE_LOGIN');
        if ($api_key == null or $login == null) {
            return false;
        }

        if (!$this->checkOrCreateTerminal($login, $api_key)) {
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
        if ($this->createPayment($products, $total,  $id_order)) {
            $this->context->smarty->assign($this->context->smarty->assign('result', "<script>window.location.replace('" . $this->paymentUrl . "'); </script>"));
            return Tools::redirect($this->paymentUrl);
        } else {
            $this->log("Не удалось создать платеж");
            $this->context->smarty->assign($this->context->smarty->assign('result', "Ошибка при создании платежа"));
            return "Ошибка при создание платежа";
        }
    }

    /**
     * @return CREATE_TERMINAL
     */
    private function createTerminal()
    {
        $api_key = Configuration::get('INVOICE_API_KEY');
        $login = Configuration::get('INVOICE_LOGIN');
        if ($api_key == null or $login == null) {
            return false;
        }

        $this->restClient = new RestClient($login, $api_key);

        $create_terminal = new CREATE_TERMINAL();
        $create_terminal->name = $this->getTerminalName();
        $create_terminal->type = "dynamical";
        $create_terminal->description = $this->getTerminalDesc();
        $create_terminal->defaultPrice = 10;
        $this->log("Создания терминала: " . json_encode($create_terminal));

        $terminal = $this->restClient->CreateTerminal($create_terminal);
        if ($terminal == null or (isset($terminal->error) and $terminal->error != null)) {
            $this->log("Ошибка создания терминала: " . json_encode($terminal));
            return false;
        } else {
            Configuration::updateValue("INVOICE_TERMINAL", $terminal->id);
        }

        return $terminal;
    }

    /**
     * @return CREATE_PAYMENT
     */
    private function createPayment($products, $total, $cart_id)
    {
        $api_key = Configuration::get('INVOICE_API_KEY');
        $login = Configuration::get('INVOICE_LOGIN');
        if ($api_key == null or $login == null) {
            return false;
        }

        $terminal = $this->checkOrCreateTerminal($login, $api_key);

        $this->log("Создание платежа: " . $cart_id);

        $create_payment = new CREATE_PAYMENT();
        $create_payment->order = $this->getOrder($total, $cart_id);
        $create_payment->settings = $this->getSettings($terminal);
        $create_payment->receipt = $this->getReceipt($products);

        $this->log("CREATE_PAYMENT: " . json_encode($create_payment));

        $paymentInfo = $this->restClient->CreatePayment($create_payment);
        $this->log("PAYMENT: " . json_encode($paymentInfo));

        if ($paymentInfo == null or (isset($paymentInfo->error) and $paymentInfo->error != null)) {
            if (isset($paymentInfo->error) and $paymentInfo->error == 3) {
                if ($this->attemptsCount < 2) {
                    $this->attemptsCount++;
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

    /**
     * @return GET_TERMINAL
     */
    public function getTerminal($login, $api_key)
    {
        $this->restClient = new RestClient($login, $api_key);

        $terminal = new GET_TERMINAL();
        $terminal->alias = Configuration::get('INVOICE_TERMINAL');
        $info = $this->restClient->GetTerminal($terminal);

        if (isset($info->error) || $info->id == null || $info->id != $terminal->alias) {
            return null;
        } else {
            return $info->id;
        }
    }

    /**
     * @return ORDER
     */
    private function getOrder($total, $cart_id)
    {
        $order = new INVOICE_ORDER();
        $order->amount = $total;
        $order->id = "$cart_id" . "-" . bin2hex(random_bytes(5));
        $order->currency = "RUB";

        return $order;
    }

    /**
     * @return SETTINGS
     */
    private function getSettings($terminal)
    {
        $url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

        $settings = new SETTINGS();
        $settings->terminal_id = $terminal;
        $settings->success_url = $url;
        $settings->fail_url = $url;

        return $settings;
    }

    /**
     * @return ITEM
     */
    private function getReceipt($products)
    {
        $receipt = array();

        foreach ($products as $product) {
            $item = new ITEM();
            $item->name = $product["name"];
            $item->price = $product["price"];
            $item->resultPrice = $product["total"];
            $item->quantity = $product["quantity"];

            array_push($receipt, $item);
        }

        return $receipt;
    }

    protected function isValidOrder()
    {
        return true;
    }

    public function log($log)
    {
        $logger = new FileLogger(0);
        $logger->setFilename(_PS_ROOT_DIR_ . "/invoice.log");
        $logger->logDebug($log);
    }

    public function checkOrCreateTerminal($login, $api_key)
    {
        $terminal = $this->getTerminal($login, $api_key);
        if ($terminal == null or empty($terminal)) {
            return $this->createTerminal();
        } else {
            return $terminal;
        }
    }

    private function getTerminalName()
    {
        $terminal_name = Configuration::get('INVOICE_TERMINAL_NAME');
        if ($terminal_name == null) {
            $terminal_name = "PrestaShop";
        }

        return $terminal_name;
    }

    private function getTerminalDesc()
    {
        $terminal_description = Configuration::get('INVOICE_TERMINAL_DESC');
        if ($terminal_description == null) {
            $terminal_description = "PrestaShopTerminal";
        }

        return $terminal_description;
    }
}
