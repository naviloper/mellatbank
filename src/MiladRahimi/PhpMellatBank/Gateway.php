<?php
/**
 * Created by PhpStorm.
 * User: Milad Rahimi <info@miladrahimi.com>
 * Date: 8/8/2017
 * Time: 12:16 AM
 */

namespace MiladRahimi\PhpMellatBank;

use MiladRahimi\PhpMellatBank\Exceptions\GatewayException;
use MiladRahimi\PhpMellatBank\Exceptions\InvalidResponseException;
use MiladRahimi\PhpMellatBank\Exceptions\MellatException;
use MiladRahimi\PhpMellatBank\Exceptions\UnsuccessfulPaymentException;
use MiladRahimi\PhpMellatBank\Values\BankResult;
use SoapClient;
use SoapFault;

class Gateway
{
    const WSDL = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';

    const SOAP_NAMESPACE = 'http://interfaces.core.sw.bps.com/';

    const GATEWAY_URL = 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat';

    /**
     * Payment options
     *
     * @var array
     */
    private $options = [];

    /**
     * Gateway constructor.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->options = [
            'terminalId' => $options['terminalId'],
            'userName' => $options['userName'],
            'userPassword' => $options['userPassword'],
            'callBackUrl' => $options['callBackUrl'],
            'payerId' => 0,
        ];
    }

    /**
     * Request a new payment
     *
     * @param int $amount
     * @param string $additionalData
     * @return string RefId
     * @throws GatewayException
     * @throws MellatException
     */
    public function requestPayment($amount, $additionalData = '')
    {
        $client = $this->createSoapClient();

        $parameters = $this->options;

        $parameters['orderId'] = time() . mt_rand(100000, 999999);
        $parameters['amount'] = $amount;
        $parameters['localDate'] = date('Ymd');
        $parameters['localTime'] = date('His');
        $parameters['additionalData'] = $additionalData;

        try {
            $result = $client->bpPayRequest($parameters);
        } catch (SoapFault $e) {
            throw new GatewayException('Gateway does not respond', 0, $e);
        }

        $resultArray = explode(',', $result);
        $response = $resultArray[0];

        if ($response != 0) {
            throw new MellatException($response);
        }

        return $resultArray[1];
    }

    /**
     * Get action url for payment html form
     *
     * @return string url
     */
    public function formActionUrl()
    {
        return self::GATEWAY_URL;
    }

    /**
     * Get ResCode if the payment is successful or false if not
     *
     * @return string|false ResCode
     */
    public function checkPayment()
    {
        if (isset($_POST['ResCode']) && $_POST['ResCode'] == 0) {
            return $_POST['RefId'];
        }

        return false;
    }

    /**
     * Verify the payment and get bank response
     * @return BankResult
     * @throws InvalidResponseException
     * @throws MellatException
     * @throws GatewayException
     */
    public function verifyPayment()
    {
        if (isset($_POST['ResCode']) == false) {
            throw new InvalidResponseException();
        }

        if ($_POST['ResCode'] != 0) {
            throw new MellatException($_POST['ResCode']);
        }

        $client = $this->createSoapClient();

        $parameters = array(
            'terminalId' => $this->options['terminalId'],
            'userName' => $this->options['userName'],
            'userPassword' => $this->options['userPassword'],
            'orderId' => $_POST['SaleOrderId'],
            'saleOrderId' => $_POST['SaleOrderId'],
            'saleReferenceId' => $_POST['SaleReferenceId']
        );

        $client->bpVerifyRequest($parameters);

        $inquiryResult = $client->call('bpInquiryRequest', $parameters, self::SOAP_NAMESPACE);
        if ($inquiryResult != 0) {
            throw new MellatException($inquiryResult);
        }

        $client->bpSettleRequest($parameters);

        $bankResult = new BankResult();
        $bankResult->refId = isset($_POST['RefId']) ?: null;
        $bankResult->resCode = $_POST['ResCode'];
        $bankResult->saleOrderId = isset($_POST['SaleOrderId']) ?: null;
        $bankResult->saleReferenceId = isset($_POST['SaleReferenceId'])?: null;

        return $bankResult;
    }

    /**
     * @return object
     * @throws GatewayException
     */
    private function createSoapClient()
    {
        try {
            /** @var object $client */
            $client = new SoapClient(self::WSDL);
        } catch (SoapFault $e) {
            throw new GatewayException('Gateway is not available', 0, $e);
        }

        return $client;
    }
}