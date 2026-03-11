<?php

namespace Datafast\Payment\Model;
class Url{ 
    const basePage= 'oppwa.com';
}
class Environment
{
    const test = 'https://test.'.Url::basePage;
    const test_2 = 'https://eu-test.'.Url::basePage;
    const production = 'https://'.Url::basePage;
    const production_2 = 'https://eu-prod.'.Url::basePage;
    public function Url($ambiente,$urlTest,$urlProd){ 
      if ($ambiente == "yes") {
        $envUrl = '';
        switch ($urlTest) {
          case 'test':
            $envUrl = Environment::test;
          break;
          case 'test_2':
            $envUrl = Environment::test_2;
          break; 
          default:
            $envUrl = Environment::test;
          break;
        } 
        return array($envUrl,false);
      } else {  
        $envUrl = '';
        switch ($urlProd) {
          case 'production':
            $envUrl = Environment::production;
          break;
          case 'production_2':
            $envUrl = Environment::production_2;
          break; 
          default:
            $envUrl = Environment::production;
          break;
        }   
        return array($envUrl,true);
      }   
    }
} 
class Routes{
    const paymentWidget = '/v1/paymentWidgets.js';
    const getCheckoutId = '/v1/checkouts';
    const deleteToken = '/v1/registrations';
    const refund = '/v1/payments';
    const testConection = '/v1/checkouts';
    const searchTransactionByPaymentId = '/v1/query';
} 