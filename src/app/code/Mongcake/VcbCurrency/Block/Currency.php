<?php
namespace Mongcake\VcbCurrency\Block;

use Magento\Framework\View\Element\Template;

class Currency extends Template
{
    protected $url = 'https://portal.vietcombank.com.vn/Usercontrols/TVPortal.TyGia/pXML.aspx?b=68';

    public function getExchangeRates()
    {
        $xml = simplexml_load_file($this->url);
        $rates = [];

        if ($xml) {
            //dd($xml->Exrate);
            foreach ($xml->Exrate as $rate) {
                $rates[] = [
                    'currencyCode' => (string)$rate['CurrencyCode'],
                    'buy' => (float)$rate['Buy'],
                    'transfer' => (float)$rate['Transfer'],
                    'sell' => (float)$rate['Sell']
                ];
            }
        }

        return $rates;
    }
}
