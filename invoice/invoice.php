<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Invoice extends PaymentModule
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'invoice';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'InvoiceLLC';
        $this->need_instance = 1;

        $this->bootstrap = true;

        parent::__construct();

        $this->limited_countries = array('RU');

        $this->limited_currencies = array('RUB');

        $this->displayName = $this->l('Invoice');
        $this->description = $this->l('Интеграция платежной системы Invoice');

        $this->confirmUninstall = $this->l('Вы действительно хотите удалить модуль?');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        if (extension_loaded('curl') == false)
        {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        Configuration::updateValue('INVOICE_LIVE_MODE', false);

        $state_process = new OrderState();
        foreach (Language::getLanguages() AS $language)
        {
            $state_process->name[$language['id_lang']] = 'Ожидание платежа Invoice';
        }
        $state_process -> id = 801;
        $state_process ->invoice = 1;
        $state_process ->color = "#007cf9";
        $state_process ->logable = 0;
        $state_process->template = "invoice";
        $state_process ->add();

        $state_success = new OrderState();
        $state_success -> id = 804;
        foreach (Language::getLanguages() AS $language)
        {
            $state_success->name[$language['id_lang']] = 'Оплачено через Invoice';
        }
        $state_success ->invoice = 1;
        $state_success ->template = "invoice";
        $state_success ->color = "#007cf9";
        $state_success ->logable = 0;
        $state_success ->paid = 1;
        $state_success ->add();

        $state_error = new OrderState();
        $state_error -> id = 802;
        foreach (Language::getLanguages() AS $language)
        {
            $state_error->name[$language['id_lang']] = 'Оишбка оплаты(Invoice)';
        }
        $state_error ->template = "invoice";
        $state_error ->invoice = 1;
        $state_error ->color = "#FF6666";
        $state_error ->logable = 0;
        $state_error ->paid = 0;
        $state_error ->add();

        $state_refund = new OrderState();
        $state_refund -> id = 803;
        foreach (Language::getLanguages() AS $language)
        {
            $state_refund->name[$language['id_lang']] = 'Возврат средств(Invoice)';
        }
        $state_refund ->send_mail = 1;
        $state_refund ->template = "invoice";
        $state_refund ->invoice = 1;
        $state_refund ->color = "#FF6600";
        $state_refund ->logable = 0;
        $state_refund ->paid = 1;
        $state_refund ->add();

        Configuration::updateValue('INVOICE_PROCESS',$state_process->id);
        Configuration::updateValue('INVOICE_SUCCESS',$state_success->id);
        Configuration::updateValue('INVOICE_ERROR',$state_error->id);
        Configuration::updateValue('INVOICE_REFUND',$state_refund->id);

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('payment') &&
            $this->registerHook('paymentReturn') &&
            $this->registerHook('paymentOptions');
    }

    public function uninstall()
    {
        Configuration::deleteByName('INVOICE_LIVE_MODE');

        return parent::uninstall();
    }

    public function getContent()
    {
        if (((bool)Tools::isSubmit('submitInvoiceModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        return $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitInvoiceModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }


    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => "Настройки Invoice",
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'desc' => $this->l('Введите логин от личного кабинета Invoice'),
                        'name' => 'INVOICE_LOGIN',
                        'label' => $this->l('Login'),
                        'value' => Configuration::get('INVOICE_LOGIN')
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'INVOICE_API_KEY',
                        'label' => "Ключ API",
                        'value' => Configuration::get('INVOICE_API_KEY')
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'INVOICE_TERMINAL_NAME',
                        'label' => "Название терминала инвойс",
                        'value' => Configuration::get('INVOICE_TERMINAL_NAME')
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'INVOICE_TERMINAL_DESC',
                        'label' => "Описание терминала Invoice",
                        'value' => Configuration::get('INVOICE_TERMINAL_DESC')
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getConfigFormValues()
    {
        return array(
            'INVOICE_TERMINAL' => Configuration::get('INVOICE_TERMINAL', ""),
            'INVOICE_LOGIN' => Configuration::get('INVOICE_LOGIN', 'demo'),
            'INVOICE_API_KEY' => Configuration::get('INVOICE_API_KEY', ""),
            'INVOICE_TERMINAL_NAME' => Configuration::get('INVOICE_TERMINAL_NAME', "Магазин PrestaShop"),
            'INVOICE_TERMINAL_DESC' => Configuration::get('INVOICE_TERMINAL_DESC', "Какой-то магазин"),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    /**
     * This method is used to render the payment button,
     * Take care if the button should be displayed or not.
     */
    public function hookPayment($params)
    {
        $currency_id = $params['cart']->id_currency;
        $currency = new Currency((int)$currency_id);

        if (in_array($currency->iso_code, $this->limited_currencies) == false)
            return false;

        $this->smarty->assign('module_dir', $this->_path);

        return $this->display(__FILE__, 'views/templates/hook/payment.tpl');
    }

    /**
     * This hook is used to display the order confirmation page.
     */
    public function hookPaymentReturn($params)
    {
        if ($this->active == false)
            return;

        $order = $params['objOrder'];

        if ($order->getCurrentOrderState()->id != Configuration::get('PS_OS_ERROR'))
            $this->smarty->assign('status', 'ok');

        $this->smarty->assign(array(
            'id_order' => $order->id,
            'reference' => $order->reference,
            'params' => $params,
            'total' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
        ));

        return $this->display(__FILE__, 'views/templates/hook/confirmation.tpl');
    }

    /**
     * Return payment options available for PS 1.7+
     *
     * @param array Hook parameters
     *
     * @return array|null
     */
    public function hookPaymentOptions($params)
    {
        global $cookie, $cart;

        $total = $cart->getOrderTotal();
        $products = array();
        $cart_products = $cart->getProducts();

        foreach ($cart_products as $product) {
            $item = array(
                "name" => $product["name"],
                "quantity" => $product["cart_quantity"],
                "price" => $product["price_wt"],
                "total" => $product["total_wt"]
            );
            array_push($products, $item);
        }

        array_push($params, array("total" => $total));
        array_push($params, $products);

        if (!$this->active) {
            return;
        }
        if (!$this->checkCurrency($params['cart'])) {
            return;
        }
        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();

        $option->setCallToActionText($this->l('Оплатить через Invoice'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', $params, true));

        return [
            $option
        ];
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function log($log) {
        $logger = new FileLogger(0);
        $logger->setFilename(_PS_ROOT_DIR_."/invoice.log");
        $logger->logDebug($log);
    }
}
